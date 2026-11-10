<div class="login-page">
    <div class="login-card">
        <h1>Quên mật khẩu</h1>
        <p>Nhập địa chỉ email admin đã đăng ký để nhận liên kết đặt lại mật khẩu.</p>
        <?php if (!empty($error)): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
        <?php if (!empty($success)): ?><div class="alert success"><?= e($success) ?></div><?php endif; ?>
        
        <?php if (!empty($resetLink)): ?>
            <div class="alert info" style="background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; margin-bottom: 16px;">
                <strong>Liên kết đặt lại mật khẩu (Mô phỏng Email):</strong><br>
                <a href="<?= e($resetLink) ?>" style="word-break: break-all; color: #2563eb; font-size: 13px;"><?= e($resetLink) ?></a>
            </div>
        <?php endif; ?>

        <form method="POST" action="/forgot-password">
            <?= csrf_field() ?>
            <div class="form-group">
                <label for="email">Địa chỉ Email quản trị</label>
                <input type="email" name="email" id="email" placeholder="admin@example.com" value="<?= e($email ?? '') ?>" required autofocus>
            </div>
            <button class="btn primary full" type="submit">Gửi yêu cầu đặt lại mật khẩu</button>
        </form>
        <div style="margin-top: 14px; text-align: center;">
            <a href="/login" style="font-size: 13px; color: #64748b; text-decoration: none;">← Quay lại đăng nhập</a>
        </div>
    </div>
</div>
