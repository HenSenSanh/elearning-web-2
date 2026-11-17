# PHP Admin — Quản lý sản phẩm, phân quyền và kho

Source PHP thuần + PDO + MySQL dùng cho bài thực hành lập trình web. Bản mở rộng cung cấp một luồng mẫu từ khai báo kho/giá đến lập phiếu, ghi sổ, xem tồn và in phiếu; sinh viên tiếp tục phát triển theo yêu cầu trong `docs/`.

## 1. Phần đã có trong khung mẫu

- Đăng nhập, danh mục và sản phẩm có hình ảnh; sản phẩm có đơn vị cơ sở và mức tồn tối thiểu.
- Phân trang thực trên database cho sản phẩm, danh mục, lịch sử giá, danh mục kho, tồn kho và phiếu; tìm kiếm/lọc được giữ khi chuyển trang.
- Phân quyền chức năng theo nhóm và ngoại lệ từng tài khoản (`UserPermission`).
- Khai báo đơn vị, kho, vị trí khu/kệ/ô và lô sản phẩm.
- Lịch sử giá nhập/xuất theo khoảng thời gian, kiểm tra chồng khoảng; không lấy giá từ sản phẩm.
- Phiếu nhập/xuất nhiều dòng: nháp → ghi sổ hoặc nháp → hủy; chụp giá và thông tin hàng tại lúc lập phiếu.
- Ghi sổ trong transaction, khóa số dư, ngăn âm tồn và ghi trùng; sổ biến động đối soát được với số dư.
- In phiếu đã ghi sổ từ dữ liệu chụp; đây là phiếu kho nội bộ.
- Dashboard tồn theo đơn vị/kho, sản phẩm chạm hoặc dưới mức tồn tối thiểu và phiếu gần đây.

Đặc tả, sơ đồ dữ liệu và wireframe từng chức năng: [docs/DE_XUAT_MO_RONG.md](docs/DE_XUAT_MO_RONG.md). Bài tập, tiêu chí nghiệm thu và rubric: [docs/BAI_TAP_SINH_VIEN.md](docs/BAI_TAP_SINH_VIEN.md).

## 2. Chạy lần đầu

Yêu cầu Docker Desktop hoặc Docker Engine có Compose. Database dùng MySQL 8.0.16 trở lên để thực thi các ràng buộc `CHECK` trong schema.

Kiểm tra `.env` ở thư mục gốc. Cấu hình mẫu dùng:

| Biến | Giá trị mẫu / ý nghĩa |
|---|---|
| `APP_URL` | `http://localhost:8090` |
| `DB_HOST` | `mysql` — tên service trong mạng Docker |
| `DB_PORT` | `3306` bên trong Docker; cổng host của MySQL là `3308` |
| `DB_DATABASE` | `admin_product_db` |
| `DB_USERNAME`, `DB_PASSWORD` | Tài khoản ứng dụng trong `.env` |
| `DB_ROOT_PASSWORD` | Mật khẩu root dùng khi tạo database |
| `SESSION_NAME` | Tên cookie session |
| `UPLOAD_MAX_SIZE` | `2097152` byte, tương đương 2 MB |

`database/init.sql` khai báo database mẫu `admin_product_db`. Nếu đổi tên database, phải sửa tương ứng trong script cài mới và `.env`.

```bash
docker compose up -d --build
docker compose logs mysql
```

Đợi MySQL khởi tạo xong trước khi đăng nhập:

- Trang quản trị: [http://localhost:8090](http://localhost:8090).
- phpMyAdmin: [http://localhost:8091](http://localhost:8091), server `mysql`, tài khoản ứng dụng trong `.env`.

Các tài khoản sau chỉ phục vụ dữ liệu minh họa của **cài mới**:

| Tài khoản | Mật khẩu mẫu | Nhóm | Khả năng chính |
|---|---|---|---|
| `admin` | `admin123` | ADMIN | Tất cả quyền của khung mẫu |
| `clerk` | `clerk123` | STOCK_CLERK | Xem, lập/hủy nháp nhập xuất, in; không ghi sổ |
| `manager` | `manager123` | STOCK_MANAGER | Xem, quản lý danh mục kho, ghi sổ và in; không lập phiếu |
| `viewer` | `viewer123` | VIEWER | Xem dashboard, sản phẩm, danh mục, tồn và phiếu; không sửa/in |

Các nhóm/quyền được lưu trong database, có thể thay đổi ở `/access`. Tên `admin` không tạo cơ chế bỏ qua phân quyền.

## 3. Nâng cấp database cũ, giữ dữ liệu

Áp dụng mục này khi đã chạy phiên bản chỉ có `admins`, `categories`, `products` và volume MySQL đã tồn tại. Docker chỉ chạy `init.sql` khi thư mục dữ liệu MySQL còn trống; khởi động lại container không nâng cấp schema.

1. Dừng thao tác ghi trên ứng dụng; khởi động MySQL nếu cần:

   ```bash
   docker compose up -d mysql
   ```

2. Sao lưu ra file riêng trước khi chạy migration. Lệnh đọc mật khẩu từ môi trường của container:

   ```bash
   docker compose exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump -uroot --single-transaction "$MYSQL_DATABASE"' > backup-before-001.sql
   ```

3. Thử trên bản sao trước, rồi chạy **một lần** theo thứ tự `001_inventory_access.sql` → `002_e7_access_and_catalog.sql` trên database cũ (nếu database đã chạy `001`, chỉ chạy `002`):

   ```bash
   docker compose exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot "$MYSQL_DATABASE"' < database/migrations/001_inventory_access.sql
   docker compose exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot "$MYSQL_DATABASE"' < database/migrations/002_e7_access_and_catalog.sql
   ```

4. Kiểm tra dấu migration và dữ liệu gốc đã được lưu để đối chiếu:

   ```bash
   docker compose exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot "$MYSQL_DATABASE" -e "SELECT * FROM schema_migrations; SELECT * FROM legacy_product_stock_prices;"'
   docker compose up -d --build
   ```

Migration tạo lịch sử giá nhập và xuất từ giá cũ, tạo phiếu nhập số dư đầu `OPENING-MIGRATION-001`, dòng sổ biến động và số dư kho MAIN/DEFAULT/lô NOLOT. Bản gốc số lượng/giá được giữ trong `legacy_product_stock_prices` trước khi bỏ hai cột `quantity`, `unit_price` khỏi `products`.

Giá cũ được dùng cho cả hai loại giá ban đầu là giả định chuyển đổi, không chứng minh đó là giá vốn. Các tài khoản `admins` cũ nhận nhóm ADMIN để giữ quyền quản trị; hãy rà lại và phân nhóm sau nâng cấp. Migration không thêm tài khoản clerk/manager/viewer vào dữ liệu cũ.

MySQL DDL tự commit. Nếu script lỗi giữa chừng, không coi toàn bộ migration đã rollback và không chạy lại ngay; xem lỗi, kiểm tra schema, xử lý trên bản sao hoặc khôi phục từ bản sao lưu. Không xóa volume để nâng cấp database có dữ liệu cần giữ. Không đưa file sao lưu chứa dữ liệu cá nhân lên kho mã nguồn.

## 4. Luồng thao tác mẫu

1. Vào **Danh mục kho**, tạo đơn vị, kho và vị trí. Vị trí gồm khu, kệ, ô; lô sản phẩm được quản lý riêng.
2. Tạo sản phẩm, chọn đơn vị cơ sở và mức tồn tối thiểu. Sản phẩm mới tự có lô `NOLOT`; tạo thêm lô có hạn dùng khi cần.
3. Vào **Giá theo thời gian**, tạo giá nhập và giá xuất có hiệu lực tại ngày dự kiến lập phiếu.
4. Đăng nhập `clerk`, lập phiếu nhập nháp, chọn sản phẩm/vị trí/lô và số lượng.
5. Đăng nhập `manager`, xem rồi ghi sổ phiếu nhập. Kiểm tra tồn tăng ở đúng vị trí/lô.
6. Lập và ghi sổ phiếu xuất theo luồng tương tự. Xuất vượt tồn hoặc lô đã quá hạn sẽ bị từ chối toàn phiếu.
7. Với quyền in, mở phiếu đã ghi sổ và chọn **In phiếu**; có thể lưu PDF từ hộp thoại in của trình duyệt.

Mọi đơn giá được server lấy từ bảng lịch sử theo loại phiếu/ngày phiếu. Phiếu nháp chưa đổi tồn; ghi sổ giữ nguyên giá đã chụp lúc lập nháp. Phiếu đã ghi sổ bất biến, phiếu nháp sai phải hủy và tạo lại.

Khoảng giá dùng `[valid_from, valid_to)`, cho phép hai khoảng tiếp giáp. Ngoài thao tác thêm khoảng mới (`action=create`), màn hình `/prices` đã bổ sung khung **Đóng khoảng mở & Lập mức giá kế tiếp (Nguyên tử)** (`action=close_open`) dùng transaction + `FOR UPDATE` để đóng khoảng `valid_to IS NULL` bằng thời điểm `T` và mở ngay khoảng tiếp theo từ `[T, Đến)`.

Số lượng là số nguyên theo đơn vị cơ sở. Sau khi sản phẩm có dòng phiếu, không đổi đơn vị cơ sở. Ngày phiếu không ở tương lai; lùi ngày dùng để chọn giá, không tái tính tồn lịch sử — ghi sổ luôn xét số dư hiện tại. Danh mục kho đã ngừng sử dụng (`is_active = 0`) không thể chọn khi lập sản phẩm hoặc phiếu mới nhưng giữ nguyên toàn vẹn lịch sử chứng từ đã phát sinh.

## 5. Route và quyền

Tất cả route nghiệp vụ cần đăng nhập. POST thay đổi dữ liệu cần CSRF; ẩn nút không thay thế kiểm tra quyền ở server. Mọi request đều truy vấn trạng thái `is_active` và quyền hiện hành trong DB; tài khoản bị khóa bị vô hiệu phiên ngay ở request kế tiếp.

| Route | Phương thức / chức năng | Quyền |
|---|---|---|
| `/login` | GET/POST đăng nhập (chặn tài khoản bị khóa `is_active = 0`) | Công khai |
| `/logout` | POST đăng xuất, cần CSRF | Đã đăng nhập |
| `/dashboard` | GET dashboard | `dashboard.view` |
| `/products` | GET tìm kiếm/lọc/phân trang DB (`per_page` 5/10/20/50) và `?export=csv` | `products.view` |
| `/products/create`, `/products/edit?id=…` | GET/POST tạo/sửa | `products.manage` |
| `/products/delete` | POST xóa sản phẩm chưa có dữ liệu tham chiếu | `products.manage` |
| `/categories` | GET danh mục sản phẩm | `categories.view` |
| `/categories/create`, `/categories/edit?id=…`, `/categories/delete` | Tạo/sửa; POST xóa | `categories.manage` |
| `/warehouse-catalog?tab=warehouses\|locations\|lots\|units` | GET xem / POST thêm, sửa inline (`update`), ngừng dùng (`toggle`), xóa an toàn (`delete`) | `warehouse.manage` |
| `/prices` | GET lịch sử (lọc sản phẩm/loại giá) / POST `create` hoặc `close_open` | GET: `prices.view`; POST: thêm `prices.manage` |
| `/inventory` | GET tồn theo sản phẩm/kho/vị trí/lô + đối soát tồn + `?export=csv` | `inventory.view` |
| `/inventory/movements` | GET sổ biến động kho chỉ đọc (phân trang DB, lọc, `?export=csv`) | `inventory.view` |
| `/documents` | GET danh sách phiếu (hiển thị số dòng, tổng tiền, `per_page`) | `documents.view` |
| `/documents/create?type=receipt` | GET/POST lập phiếu nhập | `receipts.create` |
| `/documents/create?type=issue` | GET/POST lập phiếu xuất | `issues.create` |
| `/documents/show?id=…` | GET chi tiết phiếu | `documents.view` |
| `/documents/post` | POST ghi sổ | `receipts.post` hoặc `issues.post` theo loại phiếu |
| `/documents/cancel` | POST hủy nháp | `receipts.create` hoặc `issues.create` theo loại phiếu |
| `/documents/print?id=…` | GET in phiếu đã ghi sổ | `documents.view` và `documents.print` |
| `/access?tab=permissions\|users\|roles\|audit` | GET xem / POST phân quyền, CRUD/khóa-mở/đổi mật khẩu tài khoản, tạo/đổi tên nhóm, xem nhật ký `permission_audit_logs` | `access.manage` |

Thứ tự quyết định quyền: **Từ chối riêng > Cho phép riêng > Hợp quyền các nhóm > Từ chối mặc định**. Chọn “Kế thừa nhóm” xóa ngoại lệ tài khoản. Thay đổi áp dụng từ request tiếp theo; không cần đăng nhập lại. Hệ thống bảo vệ không cho tự thu hồi `access.manage`, tự khóa tài khoản đang đăng nhập, hoặc làm mất tài khoản hoạt động cuối cùng có quyền `access.manage`.

## 6. Cấu trúc chính

```text
app/public/index.php                Router và xử lý request
app/public/assets/                  CSS, JavaScript, định dạng in
app/src/                            PDO, xác thực, quyền, retry deadlock, repository/nghiệp vụ
app/views/                          Giao diện sản phẩm, kho, biến động, giá, phiếu, phân quyền (E7)
database/init.sql                   Schema (001 + 002) + dữ liệu demo cho cài mới
database/migrations/001_inventory_access.sql
                                    Nâng cấp database ba bảng cũ lên kho & RBAC, chạy một lần
database/migrations/002_e7_access_and_catalog.sql
                                    Nâng cấp E7 (admins.is_active, permission_audit_logs, danh mục is_active)
docs/DE_XUAT_MO_RONG.md              Đặc tả và giao diện mẫu
docs/BAI_TAP_SINH_VIEN.md            Bài thực hành và tiêu chí nghiệm thu
docs/BAO_CAO_NGHIEM_THU.md           Báo cáo thiết kế, quy tắc nghiệp vụ, ERD, ma trận quyền & ca kiểm thử
tests/integration.php               Bộ test tích hợp chuẩn (70 checks) trên admin_product_test
tests/integration_extended.php      Bộ test mở rộng (deadlock retry, close_open, E7, đối soát)
tests/http_smoke.py                 Kiểm thử HTTP đọc trang, CSRF 419 và chặn phân quyền (31 checks)
tests/concurrency_test.py           Kiểm thử cạnh tranh 2 session độc lập & vô hiệu phiên khi khóa tài khoản
tests/seed_practice.php             Script CLI nạp dữ liệu thực hành (>= 35 SP / 3 danh mục, nhiều kho/lô/giá)
```

Tài khoản nằm trong `admins`; RBAC & kiểm toán dùng `roles`, `permissions`, `user_roles`, `role_permissions`, `user_permissions`, `permission_audit_logs`. Tồn dùng `stock_balances` theo khóa sản phẩm/vị trí/lô; mọi thay đổi tồn qua `inventory_documents`, `inventory_lines`, `stock_movements`. Giá nằm trong `product_prices`.

## 7. Kiểm tra và giới hạn còn lại

Chạy dịch vụ trước, sau đó chạy bộ kiểm thử từ thư mục gốc:

```bash
docker compose up -d --build
sh tests/run.sh
python tests/http_smoke.py http://localhost:8090
python tests/concurrency_test.py http://localhost:8090
```

Khi chạy test mở rộng & seeder trong container PHP đối với `admin_product_test` hoặc `admin_product_db`:
- Test tích hợp mở rộng (`DB_DATABASE=admin_product_test`): `tests/integration_extended.php`
- Nạp dữ liệu thực hành (tối thiểu 35 sản phẩm qua 3 danh mục, nhiều kho/vị trí/lô/giá): `tests/seed_practice.php`

### Giới hạn còn lại (chưa triển khai)

Đúng theo yêu cầu trung thực phạm vi bài làm, các phần mở rộng sau **chưa được triển khai**:
- **E1**: Chưa có giới hạn dữ liệu / phân quyền chi tiết theo từng kho.
- **E2**: Chưa có quy trình trình duyệt (`submitted`) hay chặn người lập tự duyệt / tự ghi sổ phiếu.
- **E3**: Chưa có nghiệp vụ phiếu chuyển kho nội bộ giữa 2 kho/vị trí.
- **E4**: Chưa có nghiệp vụ đảo phiếu / trả hàng.
- **E5**: Chưa có quy đổi nhiều đơn vị tính (thùng/hộp/cái).
- **E6**: Chưa có tính giá vốn FIFO / bình quân gia quyền & khóa kỳ kế toán, kiểm kê, thuế hay công nợ.

Nếu không kết nối MySQL, kiểm tra log service và `DB_HOST=mysql`. Nếu database đang có volume nhưng thiếu bảng mới, làm theo mục migration (`001` và `002`) thay vì chạy lại `init.sql`. Nếu trùng cổng, đổi cổng phía host trong `docker-compose.yml`; không đổi cổng nội bộ MySQL `3306` trong cấu hình ứng dụng.
