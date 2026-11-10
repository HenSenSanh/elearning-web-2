<div class="login-page">
    <div class="login-card">
        <h1>Đặt lại mật khẩu mới</h1>
        <p>Thiết lập mật khẩu mới cho tài khoản: <strong><?= e($email ?? '') ?></strong></p>
        <?php if (!empty($error)): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
        
        <form method="POST" action="/reset-password">
            <?= csrf_field() ?>
            <input type="hidden" name="token" value="<?= e($token ?? '') ?>">
            
            <div class="form-group">
                <label for="password">Mật khẩu mới <span class="required">*</span></label>
                <input type="password" name="password" id="password" placeholder="Tối thiểu 6 ký tự" required autofocus>
            </div>
            
            <div class="form-group">
                <label for="password_confirm">Xác nhận mật khẩu mới <span class="required">*</span></label>
                <input type="password" name="password_confirm" id="password_confirm" placeholder="Nhập lại mật khẩu mới" required>
            </div>
            
            <button class="btn primary full" type="submit">Cập nhật mật khẩu mới</button>
        </form>
        <div style="margin-top: 14px; text-align: center;">
            <a href="/login" style="font-size: 13px; color: #64748b; text-decoration: none;">← Quay lại đăng nhập</a>
        </div>
    </div>
</div>
