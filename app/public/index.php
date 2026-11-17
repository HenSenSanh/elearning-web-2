<?php
date_default_timezone_set('Asia/Ho_Chi_Minh');
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/permission_repository.php';
require_once __DIR__ . '/../src/category_repository.php';
require_once __DIR__ . '/../src/product_repository.php';
require_once __DIR__ . '/../src/price_repository.php';
require_once __DIR__ . '/../src/warehouse_repository.php';
require_once __DIR__ . '/../src/inventory_repository.php';

$path = rtrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/') ?: '/';
function view(string $view, array $data = []): void {
    extract($data);
    require __DIR__ . '/../views/layouts/header.php';
    require __DIR__ . '/../views/' . $view . '.php';
    require __DIR__ . '/../views/layouts/footer.php';
}
function home_path(): string {
    foreach (['/dashboard'=>'dashboard.view','/inventory'=>'inventory.view','/documents'=>'documents.view','/products'=>'products.view',
        '/categories'=>'categories.view','/prices'=>'prices.view','/warehouse-catalog'=>'warehouse.manage','/access'=>'access.manage'] as $route=>$permission) {
        if (can($permission)) return $route;
    }
    return '/dashboard';
}
function saved(string $message, string $path): void { $_SESSION['flash'] = $message; redirect($path); }
function failed(string $message, string $path): void { $_SESSION['flash_error'] = $message; redirect($path); }
function find_document_or_404(int $id): array {
    $document = document_find($id);
    if (!$document) { http_response_code(404); exit('Không tìm thấy phiếu.'); }
    return $document;
}
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)($_GET['per_page'] ?? 20);
$perPage = in_array($perPage, [5,10,20,50], true) ? $perPage : 20;
try {
    switch ($path) {
        case '/': require_admin(); redirect(home_path()); break;
        case '/login':
            $error = '';
            if (is_post()) {
                verify_csrf(); $result = admin_login(trim($_POST['username'] ?? ''), $_POST['password'] ?? '');
                if ($result['success']) redirect(home_path());
                $error = $result['message'];
            }
            view('auth/login',['title'=>'Đăng nhập quản trị kho','error'=>$error]); break;
        case '/logout': require_post(); verify_csrf(); admin_logout(); break;
        case '/dashboard':
            require_permission('dashboard.view');
            view('dashboard/index',['title'=>'Tổng quan tồn kho','summary'=>inventory_dashboard(),'productCount'=>product_count()]); break;
        case '/categories':
            require_permission('categories.view'); $result = category_paginate($page,$perPage);
            view('categories/index',['title'=>'Danh mục sản phẩm','categories'=>$result['items'],'result'=>$result]); break;
        case '/categories/create':
        case '/categories/edit':
            require_permission('categories.manage');
            $editing = $path === '/categories/edit'; $category = $editing ? category_find((int)($_GET['id'] ?? 0)) : null;
            if ($editing && !$category) { http_response_code(404); exit('Không tìm thấy danh mục.'); }
            $error = '';
            if (is_post()) {
                verify_csrf(); $name = trim($_POST['name'] ?? '');
                $nameLength = preg_match_all('/./us',$name);
                if ($nameLength === false || $nameLength < 1 || $nameLength > 150) $error = 'Tên danh mục cần từ 1 đến 150 ký tự UTF-8.';
                else {
                    if ($editing) category_update((int)$category['id'],$name,trim($_POST['description'] ?? ''),isset($_POST['status'])?1:0);
                    else category_create($name,trim($_POST['description'] ?? ''),isset($_POST['status'])?1:0);
                    saved('Đã lưu danh mục.','/categories');
                }
                if ($editing) $category = array_merge($category,$_POST,['status'=>isset($_POST['status'])?1:0]);
            }
            view('categories/' . ($editing?'edit':'create'),['title'=>$editing?'Sửa danh mục':'Thêm danh mục','category'=>$category,'error'=>$error]); break;
        case '/categories/delete':
            require_permission('categories.manage'); require_post(); verify_csrf();
            category_delete((int)($_POST['id'] ?? 0)); saved('Đã xóa danh mục.','/categories'); break;
        case '/products':
            require_permission('products.view'); $keyword = trim($_GET['keyword'] ?? ''); $categoryId = (int)($_GET['category_id'] ?? 0);
            if (($_GET['export'] ?? '') === 'csv') {
                $rows = array_map(fn($p) => [
                    $p['id'], $p['sku'] ?: '', $p['name'], $p['category_name'], $p['unit_name'] ?? '',
                    $p['stock_quantity'], $p['min_stock'], $p['status'] ? 'Hoạt động' : 'Ẩn',
                ], product_export_rows($keyword, $categoryId));
                csv_download('san_pham_' . date('Ymd_His') . '.csv', ['ID', 'SKU', 'Tên sản phẩm', 'Danh mục', 'Đơn vị', 'Tồn hiện tại', 'Ngưỡng tối thiểu', 'Trạng thái'], $rows);
            }
            view('products/index',['title'=>'Sản phẩm','result'=>product_paginate($keyword,$categoryId,$page,$perPage),
                'categories'=>category_all(),'keyword'=>$keyword,'categoryId'=>$categoryId]); break;
        case '/products/create':
        case '/products/edit':
            require_permission('products.manage'); $editing = $path === '/products/edit';
            $product = $editing ? product_find((int)($_GET['id'] ?? 0)) : [];
            if ($editing && !$product) { http_response_code(404); exit('Không tìm thấy sản phẩm.'); }
            $unitLocked = $editing ? product_has_document_lines((int)$product['id']) : false;
            $old = $product; $errors = [];
            if (is_post()) {
                verify_csrf();
                $postInput = $_POST;
                if ($unitLocked) $postInput['unit_id'] = $product['unit_id'];
                $old = array_merge($product,$postInput,['status'=>isset($_POST['status'])?1:0]);
                $errors = product_validate($postInput);
                if (!$errors) {
                    $newImage = null;
                    try {
                        $newImage = upload_product_image($_FILES['image'] ?? []);
                        $data = product_data($postInput,$newImage ?: ($product['image_path'] ?? null));
                        if ($editing) product_update((int)$product['id'],$data); else product_create($data);
                        if ($newImage && $editing) delete_product_image($product['image_path']);
                        saved('Đã lưu sản phẩm. Tồn và giá được quản lý riêng.','/products');
                    } catch (Throwable $e) {
                        if ($newImage) delete_product_image($newImage);
                        if ($e instanceof PDOException) $errors[] = 'Không thể lưu. Kiểm tra SKU trùng hoặc dữ liệu liên quan.';
                        else $errors[] = $e->getMessage();
                    }
                }
            }
            $unitsQuery = $editing
                ? db()->prepare('SELECT * FROM units WHERE is_active=1 OR id=? ORDER BY name')
                : db()->prepare('SELECT * FROM units WHERE is_active=1 ORDER BY name');
            $unitsQuery->execute($editing ? [(int)$product['unit_id']] : []);
            view('products/' . ($editing?'edit':'create'),['title'=>$editing?'Sửa sản phẩm':'Thêm sản phẩm','old'=>$old,'errors'=>$errors,
                'unitLocked'=>$unitLocked,'categories'=>category_all(),'units'=>$unitsQuery->fetchAll()]); break;
        case '/products/delete':
            require_permission('products.manage'); require_post(); verify_csrf(); $product = product_find((int)($_POST['id'] ?? 0));
            if ($product) { product_delete((int)$product['id']); delete_product_image($product['image_path']); }
            saved('Đã xóa sản phẩm.','/products'); break;
        case '/inventory':
            require_permission('inventory.view'); $filters = ['keyword'=>trim($_GET['keyword'] ?? ''),'warehouse_id'=>(int)($_GET['warehouse_id'] ?? 0)];
            if (($_GET['export'] ?? '') === 'csv') {
                $rows = array_map(fn($r) => [
                    $r['sku'] ?: '', $r['name'], $r['warehouse_name'], $r['location_code'],
                    $r['lot_code'], $r['expires_on'] ?? 'Không giới hạn', $r['quantity'], $r['unit_name'], $r['min_stock'],
                ], inventory_export_rows($filters));
                csv_download('ton_kho_' . date('Ymd_His') . '.csv', ['SKU', 'Sản phẩm', 'Kho', 'Vị trí', 'Lô', 'Hạn dùng', 'Tồn', 'Đơn vị', 'Ngưỡng tối thiểu'], $rows);
            }
            view('inventory/index',['title'=>'Tồn kho theo kho / vị trí / lô','result'=>inventory_paginate($filters,$page,$perPage),
                'filters'=>$filters,'warehouses'=>inventory_warehouses(),'reconciliation'=>inventory_reconciliation_summary()]); break;
        case '/inventory/movements':
            require_permission('inventory.view');
            $filters = [
                'product_id' => (int)($_GET['product_id'] ?? 0),
                'warehouse_id' => (int)($_GET['warehouse_id'] ?? 0),
                'from_date' => trim($_GET['from_date'] ?? ''),
                'to_date' => trim($_GET['to_date'] ?? ''),
                'keyword' => trim($_GET['keyword'] ?? ''),
            ];
            if (($_GET['export'] ?? '') === 'csv') {
                $rows = array_map(fn($m) => [
                    $m['document_date'], $m['posted_at'] ?: '', $m['document_number'],
                    document_type_label($m['document_type']), $m['product_sku'] ?: '', $m['product_name'],
                    $m['warehouse_code'], $m['location_code'], $m['lot_code'],
                    $m['quantity'], $m['unit_name'], $m['unit_price'], $m['poster_username'] ?: 'Hệ thống',
                ], inventory_movements_export_rows($filters));
                csv_download('so_bien_dong_' . date('Ymd_His') . '.csv', ['Ngày chứng từ', 'Ngày ghi sổ', 'Số phiếu', 'Loại phiếu', 'SKU', 'Sản phẩm', 'Mã kho', 'Vị trí', 'Lô', 'Biến động', 'Đơn vị', 'Đơn giá chụp', 'Người ghi sổ'], $rows);
            }
            view('inventory/movements',[
                'title'=>'Sổ biến động kho',
                'result'=>inventory_movements_paginate($filters,$page,$perPage),
                'filters'=>$filters,
                'products'=>inventory_products(),
                'warehouses'=>inventory_warehouses(),
            ]); break;
        case '/documents':
            require_permission('documents.view'); $filters = ['keyword'=>trim($_GET['keyword'] ?? ''),'type'=>$_GET['type'] ?? '','status'=>$_GET['status'] ?? ''];
            view('documents/index',['title'=>'Phiếu nhập / xuất kho','result'=>document_paginate($filters,$page,$perPage),'filters'=>$filters]); break;
        case '/documents/create':
            $type = is_post() ? ($_POST['document_type'] ?? '') : ($_GET['type'] ?? 'receipt');
            require_permission(document_permission($type,'create')); $errors = []; $old = ['document_type'=>$type,'document_date'=>date('Y-m-d\\TH:i')];
            if (is_post()) {
                verify_csrf(); $old = $_POST; $actorId = (int)($_SESSION['admin_id'] ?? 0);
                try { $id = document_create($_POST,$actorId); saved('Đã tạo phiếu nháp. Tồn kho chưa thay đổi.','/documents/show?id=' . $id); }
                catch (InvalidArgumentException $e) { $errors[] = $e->getMessage(); }
            }
            view('documents/create',['title'=>'Lập phiếu ' . ($type==='receipt'?'nhập':'xuất'),'options'=>document_form_options(),'old'=>$old,'errors'=>$errors]); break;
        case '/documents/show':
            require_permission('documents.view');
            view('documents/show',['title'=>'Chi tiết phiếu','document'=>find_document_or_404((int)($_GET['id'] ?? 0))]); break;
        case '/documents/post':
            require_admin(); require_post(); verify_csrf(); $id = (int)($_POST['id'] ?? 0); $actorId = (int)($_SESSION['admin_id'] ?? 0);
            document_post($id,$actorId); saved('Đã ghi sổ phiếu và cập nhật tồn kho.','/documents/show?id=' . $id); break;
        case '/documents/cancel':
            require_admin(); require_post(); verify_csrf(); $id = (int)($_POST['id'] ?? 0); $actorId = (int)($_SESSION['admin_id'] ?? 0);
            document_cancel($id,$actorId); saved('Đã hủy phiếu nháp.','/documents/show?id=' . $id); break;
        case '/documents/print':
            require_permission('documents.print'); $document = find_document_or_404((int)($_GET['id'] ?? 0));
            if ($document['status'] !== 'posted') throw new InvalidArgumentException('Chỉ được in phiếu đã ghi sổ.');
            view('documents/print',['title'=>'In ' . $document['number'],'document'=>$document]); break;
        case '/prices':
            require_permission('prices.view'); $error = ''; $old = []; $productId = (int)($_GET['product_id'] ?? 0); $priceType = (string)($_GET['price_type'] ?? '');
            if (is_post()) {
                require_permission('prices.manage'); verify_csrf(); $old = $_POST; $actorId = (int)($_SESSION['admin_id'] ?? 0);
                $action = (string)($_POST['action'] ?? 'create');
                try {
                    if ($action === 'close_open') {
                        price_close_and_open_next($_POST,$actorId);
                        saved('Đã đóng khoảng giá mở và áp dụng mức giá mới.','/prices?product_id=' . (int)$_POST['product_id']);
                    } else {
                        price_create($_POST,$actorId);
                        saved('Đã thêm khoảng giá.','/prices?product_id=' . (int)$_POST['product_id']);
                    }
                }
                catch (InvalidArgumentException $e) { $error = $e->getMessage(); }
            }
            view('prices/index',['title'=>'Giá sản phẩm theo thời gian','result'=>price_paginate($productId,$page,$perPage,$priceType),
                'products'=>db()->query('SELECT id,name,sku FROM products ORDER BY name')->fetchAll(),'productId'=>$productId,'priceType'=>$priceType,'error'=>$error,'old'=>$old]); break;
        case '/warehouse-catalog':
            require_permission('warehouse.manage'); $tab = $_GET['tab'] ?? 'warehouses'; $old = []; $error = '';
            if (!isset(warehouse_tabs()[$tab])) throw new InvalidArgumentException('Danh mục kho không tồn tại.');
            if (is_post()) {
                verify_csrf();
                $action = (string)($_POST['action'] ?? 'create');
                try {
                    if ($action === 'update') {
                        warehouse_update($tab, (int)($_POST['id'] ?? 0), $_POST);
                        saved('Đã cập nhật danh mục kho (dữ liệu đã chụp trên chứng từ lịch sử vẫn giữ nguyên).','/warehouse-catalog?tab=' . $tab);
                    } elseif ($action === 'toggle') {
                        warehouse_toggle_active($tab, (int)($_POST['id'] ?? 0), (int)($_POST['is_active'] ?? 0));
                        saved('Đã cập nhật trạng thái danh mục kho.','/warehouse-catalog?tab=' . $tab);
                    } elseif ($action === 'delete') {
                        warehouse_delete_safe($tab, (int)($_POST['id'] ?? 0));
                        saved('Đã xóa danh mục kho.','/warehouse-catalog?tab=' . $tab);
                    } else {
                        $old = $_POST;
                        warehouse_create($tab,$_POST);
                        saved('Đã thêm danh mục kho.','/warehouse-catalog?tab=' . $tab);
                    }
                }
                catch (InvalidArgumentException $e) { $error = $e->getMessage(); }
                catch (PDOException $e) { $error = 'Mã bị trùng hoặc kho/sản phẩm liên quan không tồn tại.'; }
            }
            view('warehouse/index',['title'=>'Danh mục kho, vị trí, lô, đơn vị','tab'=>$tab,'result'=>warehouse_paginate($tab,$page,$perPage),
                'options'=>warehouse_options(),'error'=>$error,'old'=>$old]); break;
        case '/access':
            require_permission('access.manage'); $error = '';
            $tab = in_array($_GET['tab'] ?? '', ['permissions','users','roles','audit'], true) ? $_GET['tab'] : 'permissions';
            if (is_post()) {
                verify_csrf();
                $actorId = (int)($_SESSION['admin_id'] ?? 0);
                $mode = (string)($_POST['mode'] ?? '');
                $targetUserId = (int)($_POST['user_id'] ?? ($_GET['user_id'] ?? 0));
                $targetRoleId = (int)($_POST['role_id'] ?? ($_GET['role_id'] ?? 0));
                try {
                    if ($mode === 'create_user') {
                        $newUid = access_create_user($_POST, $actorId);
                        saved('Đã tạo tài khoản mới.','/access?tab=users&user_id=' . $newUid);
                    } elseif ($mode === 'update_user') {
                        access_update_user($_POST, $actorId);
                        saved('Đã cập nhật tài khoản.','/access?tab=users&user_id=' . $targetUserId);
                    } elseif ($mode === 'toggle_user') {
                        access_toggle_user($_POST, $actorId);
                        saved('Đã cập nhật trạng thái tài khoản.','/access?tab=users&user_id=' . $targetUserId);
                    } elseif ($mode === 'reset_password') {
                        access_reset_password($_POST, $actorId);
                        saved('Đã đặt lại mật khẩu tài khoản.','/access?tab=users&user_id=' . $targetUserId);
                    } elseif ($mode === 'create_role') {
                        $newRid = access_create_role($_POST, $actorId);
                        saved('Đã tạo nhóm quyền mới.','/access?tab=roles&role_id=' . $newRid);
                    } elseif ($mode === 'update_role') {
                        access_update_role($_POST, $actorId);
                        saved('Đã cập nhật tên nhóm quyền.','/access?tab=roles&role_id=' . $targetRoleId);
                    } else {
                        access_save($_POST, $actorId);
                        saved('Đã cập nhật phân quyền.','/access?tab=' . $tab . '&user_id=' . $targetUserId . '&role_id=' . $targetRoleId);
                    }
                }
                catch (InvalidArgumentException $e) { $error = $e->getMessage(); }
            }
            $auditPage = max(1, (int)($_GET['audit_page'] ?? $page));
            view('access/index',[
                'title'=>'Phân quyền nhóm, tài khoản & nhật ký',
                'tab'=>$tab,
                'access'=>access_data((int)($_GET['user_id'] ?? 0),(int)($_GET['role_id'] ?? 0),$auditPage),
                'error'=>$error,
                'message'=>''
            ]); break;
        default: http_response_code(404); view('errors/index',['title'=>'Không tìm thấy trang','error'=>'404 - Đường dẫn không tồn tại.']);
    }
} catch (InvalidArgumentException $e) {
    http_response_code(422); view('errors/index',['title'=>'Không thể thực hiện','error'=>$e->getMessage()]);
} catch (PDOException $e) {
    error_log((string)$e); http_response_code($e->getCode()==='23000' ? 409 : 500);
    $message = $e->getCode()==='23000' ? 'Dữ liệu trùng hoặc đang được sử dụng. Hãy kiểm tra thông tin và thử lại.' : 'Không thể truy cập dữ liệu. Kiểm tra kết nối MySQL và chạy migration 001/002 trước khi dùng bản mở rộng.';
    // Error rendering must not query permissions again when the database is unavailable.
    echo '<!DOCTYPE html><html lang="vi"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Không thể truy cập dữ liệu</title><link rel="stylesheet" href="/assets/css/admin.css"></head><body class="login-page"><main class="login-card"><h1>Không thể xử lý dữ liệu</h1><p role="alert">' . e($message) . '</p><a class="btn secondary" href="/">Quay lại</a></main></body></html>';
} catch (Throwable $e) {
    error_log((string)$e); http_response_code(500); echo 'Có lỗi xử lý. Vui lòng kiểm tra nhật ký PHP.';
}
