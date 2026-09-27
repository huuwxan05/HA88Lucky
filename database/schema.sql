-- HA88Lucky V9 TEST-only production foundation
-- All balances and game outcomes in this schema are virtual/test data.

CREATE TABLE IF NOT EXISTS players (
  id BIGSERIAL PRIMARY KEY,
  username VARCHAR(40) UNIQUE NOT NULL,
  email VARCHAR(254) UNIQUE,
  password_hash TEXT NOT NULL,
  character_name VARCHAR(60),
  status VARCHAR(16) NOT NULL DEFAULT 'active' CHECK(status IN ('active','suspended','banned')),
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  last_login_at TIMESTAMPTZ
);

CREATE TABLE IF NOT EXISTS player_wallets (
  player_id BIGINT PRIMARY KEY REFERENCES players(id) ON DELETE CASCADE,
  balance NUMERIC(20,2) NOT NULL DEFAULT 1000000 CHECK(balance >= 0),
  version BIGINT NOT NULL DEFAULT 0,
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS wallet_ledger (
  id BIGSERIAL PRIMARY KEY,
  player_id BIGINT NOT NULL REFERENCES players(id) ON DELETE CASCADE,
  amount NUMERIC(20,2) NOT NULL,
  balance_after NUMERIC(20,2) NOT NULL,
  type VARCHAR(32) NOT NULL,
  reference_id VARCHAR(160),
  reason TEXT,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_wallet_ledger_player_created ON wallet_ledger(player_id, created_at DESC);

CREATE TABLE IF NOT EXISTS player_sessions (
  id BIGSERIAL PRIMARY KEY,
  player_id BIGINT NOT NULL REFERENCES players(id) ON DELETE CASCADE,
  token_hash CHAR(64) UNIQUE NOT NULL,
  csrf_hash CHAR(64) NOT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  last_seen_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  expires_at TIMESTAMPTZ NOT NULL,
  revoked_at TIMESTAMPTZ,
  user_agent TEXT,
  ip_address INET
);
CREATE INDEX IF NOT EXISTS idx_player_sessions_player ON player_sessions(player_id);

CREATE TABLE IF NOT EXISTS game_admin_config (
  game_code VARCHAR(64) PRIMARY KEY,
  enabled BOOLEAN NOT NULL DEFAULT TRUE,
  min_bet NUMERIC(20,2) NOT NULL DEFAULT 0,
  max_bet NUMERIC(20,2) NOT NULL DEFAULT 0,
  round_seconds INT NOT NULL DEFAULT 30 CHECK(round_seconds BETWEEN 5 AND 3600),
  difficulty VARCHAR(16) NOT NULL DEFAULT 'Normal' CHECK(difficulty IN ('Easy','Normal','Hard','Extreme')),
  updated_by BIGINT,
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS game_rounds (
  id BIGSERIAL PRIMARY KEY,
  game_code VARCHAR(64) NOT NULL,
  round_id VARCHAR(160) UNIQUE NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'open' CHECK(status IN ('open','closed','settled','cancelled')),
  result_code VARCHAR(120),
  opened_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  closes_at TIMESTAMPTZ NOT NULL,
  settled_at TIMESTAMPTZ,
  created_by BIGINT
);
CREATE INDEX IF NOT EXISTS idx_game_rounds_game_status ON game_rounds(game_code,status,opened_at DESC);

CREATE TABLE IF NOT EXISTS game_test_round_controls (
  id BIGSERIAL PRIMARY KEY,
  game_code VARCHAR(64) NOT NULL,
  round_id VARCHAR(160) NOT NULL,
  result_code VARCHAR(120) NOT NULL,
  created_by BIGINT NOT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE(game_code, round_id)
);
-- Deliberately no player_id: test controls are game/round scoped.

CREATE TABLE IF NOT EXISTS bets (
  id BIGSERIAL PRIMARY KEY,
  player_id BIGINT NOT NULL REFERENCES players(id) ON DELETE CASCADE,
  game_code VARCHAR(64) NOT NULL,
  round_id VARCHAR(160) NOT NULL REFERENCES game_rounds(round_id) ON DELETE RESTRICT,
  bet_code VARCHAR(80) NOT NULL,
  amount NUMERIC(20,2) NOT NULL CHECK(amount > 0),
  payout NUMERIC(20,2) NOT NULL DEFAULT 0 CHECK(payout >= 0),
  status VARCHAR(16) NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','won','lost','void')),
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  settled_at TIMESTAMPTZ
);
CREATE INDEX IF NOT EXISTS idx_bets_player_created ON bets(player_id,created_at DESC);
CREATE INDEX IF NOT EXISTS idx_bets_round ON bets(round_id);

CREATE TABLE IF NOT EXISTS transactions (
  id BIGSERIAL PRIMARY KEY,
  player_id BIGINT NOT NULL REFERENCES players(id) ON DELETE CASCADE,
  type VARCHAR(20) NOT NULL CHECK(type IN ('deposit_test','withdraw_test')),
  amount NUMERIC(20,2) NOT NULL CHECK(amount > 0),
  status VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','approved','rejected')),
  note TEXT,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  reviewed_at TIMESTAMPTZ,
  reviewed_by BIGINT
);
CREATE INDEX IF NOT EXISTS idx_transactions_status_created ON transactions(status,created_at DESC);

CREATE TABLE IF NOT EXISTS support_tickets (
  id BIGSERIAL PRIMARY KEY,
  player_id BIGINT REFERENCES players(id) ON DELETE SET NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'open' CHECK(status IN ('open','pending','resolved')),
  subject VARCHAR(160) NOT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE TABLE IF NOT EXISTS support_messages (
  id BIGSERIAL PRIMARY KEY,
  ticket_id BIGINT NOT NULL REFERENCES support_tickets(id) ON DELETE CASCADE,
  sender_type VARCHAR(10) NOT NULL CHECK(sender_type IN ('player','admin')),
  sender_id BIGINT,
  message TEXT NOT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_support_messages_ticket ON support_messages(ticket_id,created_at);

CREATE TABLE IF NOT EXISTS admin_audit_log (
  id BIGSERIAL PRIMARY KEY,
  admin_id BIGINT NOT NULL,
  action VARCHAR(120) NOT NULL,
  scope VARCHAR(120) NOT NULL,
  target_id VARCHAR(160),
  old_value JSONB,
  new_value JSONB,
  reason TEXT,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_admin_audit_created ON admin_audit_log(created_at DESC);

CREATE TABLE IF NOT EXISTS admin_accounts (
  id BIGSERIAL PRIMARY KEY,
  username VARCHAR(80) UNIQUE NOT NULL,
  password_hash TEXT NOT NULL,
  role VARCHAR(32) NOT NULL CHECK(role IN ('admin','super_admin','emergency_admin')),
  totp_secret_enc TEXT,
  active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  last_login_at TIMESTAMPTZ
);
CREATE TABLE IF NOT EXISTS admin_login_attempts (
  username VARCHAR(80) PRIMARY KEY,
  failures INT NOT NULL DEFAULT 0,
  locked_until TIMESTAMPTZ
);
CREATE TABLE IF NOT EXISTS api_rate_limits (
  bucket_key VARCHAR(160) PRIMARY KEY,
  count INT NOT NULL DEFAULT 0,
  window_start TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE TABLE IF NOT EXISTS admin_test_wallet_ops (
  id BIGSERIAL PRIMARY KEY,
  admin_id BIGINT NOT NULL,
  player_id BIGINT REFERENCES players(id) ON DELETE SET NULL,
  amount NUMERIC(20,2) NOT NULL,
  direction VARCHAR(8) NOT NULL CHECK(direction IN ('credit','debit')),
  reason TEXT NOT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE TABLE IF NOT EXISTS admin_support_state (
  id BIGSERIAL PRIMARY KEY,
  player_id BIGINT REFERENCES players(id) ON DELETE SET NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'open',
  message TEXT,
  updated_by BIGINT NOT NULL,
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Compatibility with the earlier V9 table.
CREATE TABLE IF NOT EXISTS game_test_config (
  game_code VARCHAR(64) PRIMARY KEY,
  difficulty VARCHAR(16) NOT NULL CHECK(difficulty IN ('Easy','Normal','Hard','Extreme')),
  updated_by BIGINT NOT NULL,
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);


-- HA88Lucky Payment Demo/Sandbox addon
-- HA88Lucky Payment Demo/Sandbox migration
-- Keeps payment configuration separate from TEST wallet.

CREATE TABLE IF NOT EXISTS payment_accounts (
  id BIGSERIAL PRIMARY KEY,
  bank_code VARCHAR(32) NOT NULL,
  bank_name VARCHAR(120) NOT NULL,
  account_name VARCHAR(120) NOT NULL,
  account_number VARCHAR(40) NOT NULL,
  bin VARCHAR(16),
  qr_template VARCHAR(64) NOT NULL DEFAULT 'compact2',
  transfer_prefix VARCHAR(32) NOT NULL DEFAULT 'HA88',
  active BOOLEAN NOT NULL DEFAULT FALSE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  created_by BIGINT REFERENCES admin_accounts(id),
  updated_by BIGINT REFERENCES admin_accounts(id)
);

CREATE UNIQUE INDEX IF NOT EXISTS ux_payment_accounts_account
  ON payment_accounts(bank_code, account_number);

CREATE TABLE IF NOT EXISTS payment_deposit_orders (
  id BIGSERIAL PRIMARY KEY,
  order_code VARCHAR(64) NOT NULL UNIQUE,
  player_id BIGINT NOT NULL REFERENCES players(id),
  payment_account_id BIGINT REFERENCES payment_accounts(id),
  amount BIGINT NOT NULL CHECK (amount > 0),
  transfer_content VARCHAR(120) NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'PENDING'
    CHECK (status IN ('PENDING','PAID','CANCELLED','EXPIRED')),
  provider_reference VARCHAR(128),
  paid_at TIMESTAMPTZ,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS payment_withdraw_orders (
  id BIGSERIAL PRIMARY KEY,
  order_code VARCHAR(64) NOT NULL UNIQUE,
  player_id BIGINT NOT NULL REFERENCES players(id),
  bank_code VARCHAR(32) NOT NULL,
  bank_name VARCHAR(120) NOT NULL,
  account_name VARCHAR(120) NOT NULL,
  account_number VARCHAR(40) NOT NULL,
  amount BIGINT NOT NULL CHECK (amount > 0),
  status VARCHAR(24) NOT NULL DEFAULT 'PENDING'
    CHECK (status IN ('PENDING','APPROVED','REJECTED','PAID','CANCELLED')),
  admin_note TEXT,
  provider_reference VARCHAR(128),
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  reviewed_at TIMESTAMPTZ,
  reviewed_by BIGINT REFERENCES admin_accounts(id)
);

CREATE INDEX IF NOT EXISTS ix_deposit_player_status
  ON payment_deposit_orders(player_id, status, created_at DESC);
CREATE INDEX IF NOT EXISTS ix_withdraw_player_status
  ON payment_withdraw_orders(player_id, status, created_at DESC);

-- Only one active receiving account at a time.
CREATE UNIQUE INDEX IF NOT EXISTS ux_one_active_payment_account
  ON payment_accounts(active) WHERE active = TRUE;

INSERT INTO payment_accounts
  (bank_code, bank_name, account_name, account_number, bin, qr_template, transfer_prefix, active)
SELECT 'DEMO', 'Ngân hàng DEMO', 'HA88 DEMO', '0000000000', NULL, 'compact2', 'HA88', TRUE
WHERE NOT EXISTS (SELECT 1 FROM payment_accounts);
