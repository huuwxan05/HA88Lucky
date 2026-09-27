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
