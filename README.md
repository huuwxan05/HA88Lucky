# HA88Lucky V9.x — TEST End-to-End Build

Bản này hoàn thiện luồng **TEST/virtual** từ giao diện → API → PostgreSQL, gồm người chơi, ví TEST, vòng chơi, cược TEST, settlement, Admin và audit.

> **TEST ONLY:** không có tiền thật, không tích hợp cổng thanh toán thật.

## Đã có

- Player register/login/logout/session.
- Mật khẩu player hash bằng Argon2id.
- Ví TEST mặc định 1,000,000.
- Ledger ví bất biến theo từng biến động.
- 13 game registry + 13 route game riêng.
- Vòng chơi server-authoritative.
- Đặt cược TEST và kiểm tra min/max.
- Settlement server-side.
- Kết quả TEST mặc định được sinh ngẫu nhiên theo round.
- Admin Super Admin có thể đặt **TEST result theo game + round**, không có `player_id` trong control này.
- Admin có thể cấu hình game, độ khó, giới hạn cược, thời lượng vòng.
- Admin TEST wallet adjustment có transaction + audit.
- Quản lý trạng thái player.
- Audit log.
- Admin login riêng, Argon2id, TOTP, session rotation, idle/absolute timeout, CSRF.
- TOTP secret được mã hóa bằng libsodium với `APP_ADMIN_TOTP_KEY`.
- Security headers, HSTS khi HTTPS, CSP, SameSite/HttpOnly/Secure cookie.
- Dockerfile + Render blueprint mẫu.
- Smoke test tự động.

## 13 game

Tài Xỉu 3D, Sic Bo 3D, Rồng Hổ 3D, Bầu Cua 3D, Blackjack 3D, Xóc Đĩa 3D, Baccarat 3D, Quyết Chiến 3D, Tiến Lên 3D, Phỏm 3D, Xì Dách 3D, Thể Thao, Xổ Số.

Các trang game hiện có **3D-style CSS arena** và engine server TEST. Đây chưa phải asset 3D GLB/GLTF thương mại hoàn chỉnh.

## API chính

Public:
- `GET /api/index.php?action=health`
- `POST /api/index.php?action=register`
- `POST /api/index.php?action=login`
- `POST /api/index.php?action=logout`
- `GET /api/index.php?action=session`
- `GET /api/index.php?action=wallet`
- `GET /api/index.php?action=game_list`
- `GET /api/index.php?action=game_config&game=<code>`
- `GET /api/index.php?action=game_round&game=<code>`
- `POST /api/index.php?action=place_bet`
- `GET /api/index.php?action=settle_round&game=<code>&round=<round_id>`

Admin:
- `POST /api/index.php?action=admin_login`
- `GET /api/index.php?action=admin_session`
- `POST /api/index.php?action=admin_logout`
- `GET /api/index.php?action=admin_overview`
- `GET /api/index.php?action=admin_games`
- `POST /api/index.php?action=admin_game_update`
- `POST /api/index.php?action=admin_round_result`
- `POST /api/index.php?action=admin_wallet_test`
- `GET /api/index.php?action=admin_players`
- `POST /api/index.php?action=admin_player_status`
- `GET /api/index.php?action=admin_transactions`
- `GET /api/index.php?action=admin_audit`
- `POST /api/index.php?action=admin_support`
- `POST /api/index.php?action=admin_create`
- `GET /api/index.php?action=admin_list_accounts`
- `POST /api/index.php?action=admin_set_active`

## PostgreSQL

Chạy `database/schema.sql` trên database PostgreSQL trước khi sử dụng đầy đủ.

Các nhóm dữ liệu chính:
- `players`
- `player_wallets`
- `wallet_ledger`
- `player_sessions`
- `game_rounds`
- `bets`
- `transactions`
- `support_tickets`
- `support_messages`
- `game_admin_config`
- `game_test_round_controls`
- `admin_accounts`
- `admin_audit_log`

## Admin bootstrap

`api/setup_admin.php` chỉ chạy bằng CLI.

Environment:
- `DATABASE_URL`
- `APP_ADMIN_TOTP_KEY`
- `ADMIN_BOOTSTRAP_USERNAME`
- `ADMIN_BOOTSTRAP_PASSWORD`
- `ADMIN_BOOTSTRAP_TOTP_SECRET`

Tạo key mã hóa TOTP:

```bash
php -r "echo base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)), PHP_EOL;"
```

Sau khi tạo Super Admin, không dùng bootstrap endpoint như một web route.

## Kiểm thử

```bash
python3 tools/smoke_test.py
php -l api/bootstrap.php
php -l api/index.php
php -l api/setup_admin.php
```

Smoke test phải trả:

`SMOKE OK: 13 games, player auth, TEST wallet/bets/settlement, admin controls, no player-targeted round result control.`

## Deploy Render

Có `Dockerfile` và `render.yaml` mẫu. Cấu hình các secret dưới Environment của Render; không commit secret thật.

Health check:
`/api/index.php?action=health`

## Giới hạn còn lại

Đây là bản TEST end-to-end, chưa phải hệ thống tiền thật. Nếu triển khai sản phẩm thương mại cần thêm kiểm thử tích hợp PostgreSQL thực tế, backup/restore, key rotation/recovery codes, monitoring, rate limiting bằng Redis ở quy mô lớn, penetration testing, và bộ asset/animation 3D hoàn chỉnh.


## Payment Demo/Sandbox (merged)

The `api/payment_demo.php` module and `database/payment_demo_migration.sql` are integrated into this V9 source.

Available demo routes:
- `payment_account_list`
- `payment_account_save`
- `payment_account_activate`
- `payment_deposit_create`
- `payment_deposit_demo_paid`
- `payment_deposit_history`
- `payment_withdraw_create`
- `payment_withdraw_history`
- `admin_withdraw_list`
- `admin_withdraw_review`

Payment is deliberately separated from the TEST wallet. The demo PAID action does not credit the wallet and the withdrawal flow does not send real funds. Real-money processing is not implemented.

Player demo payment UI: `/payment/index.html`
Admin receiving-account UI: `/admin/payment-accounts.html`

New deposits snapshot the selected receiving account by `payment_account_id`; changing the active account affects new orders only.
