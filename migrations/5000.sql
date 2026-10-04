-- Grocy Modern: household budget
--
-- Migrations of this fork live in their own number range (5000 and up).
-- Grocy records every migration by its number instead of keeping a single
-- schema version, so these never collide with upstream's - however many
-- releases come.
--
-- All amounts are integer cents. Signed like a bank statement: money coming
-- in is positive, money going out is negative.

-- A budget is a pot of money with its own categories and monthly plan: the
-- shared household one (no owner) or a personal one (owned by a user).
CREATE TABLE budget_budgets (
	id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT UNIQUE,
	name TEXT NOT NULL,
	owner_user_id INTEGER NULL,
	sort_order INTEGER NOT NULL DEFAULT 0,
	row_created_timestamp DATETIME DEFAULT (datetime('now', 'localtime'))
);

CREATE TABLE budget_categories (
	id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT UNIQUE,
	budget_id INTEGER NOT NULL,
	name TEXT NOT NULL,
	is_income TINYINT NOT NULL DEFAULT 0 CHECK(is_income IN (0, 1)),
	-- Whatever is left of the plan at the end of a month (or overspent) moves
	-- into the next one - for savings like holidays or a car reserve
	carryover TINYINT NOT NULL DEFAULT 0 CHECK(carryover IN (0, 1)),
	sort_order INTEGER NOT NULL DEFAULT 0,
	row_created_timestamp DATETIME DEFAULT (datetime('now', 'localtime'))
);

CREATE INDEX ix_budget_categories_budget ON budget_categories (budget_id);

-- The planned monthly amount of a category. A row applies from its month on,
-- until a later row replaces it - so changing the plan in October leaves the
-- plans of past months, and with them the history, untouched.
CREATE TABLE budget_plans (
	id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT UNIQUE,
	category_id INTEGER NOT NULL,
	valid_from TEXT NOT NULL, -- YYYY-MM
	amount INTEGER NOT NULL,
	UNIQUE (category_id, valid_from)
);

CREATE TABLE budget_transactions (
	id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT UNIQUE,
	budget_id INTEGER NOT NULL,
	category_id INTEGER NULL,
	date TEXT NOT NULL, -- YYYY-MM-DD
	amount INTEGER NOT NULL,
	payee TEXT NULL,
	note TEXT NULL,
	-- A transfer between two budgets is two transactions pointing at each other
	transfer_id INTEGER NULL,
	-- Set when the transaction was booked by a recurring transaction
	recurring_id INTEGER NULL,
	user_id INTEGER NULL,
	row_created_timestamp DATETIME DEFAULT (datetime('now', 'localtime'))
);

CREATE INDEX ix_budget_transactions_budget_date ON budget_transactions (budget_id, date);
CREATE INDEX ix_budget_transactions_category ON budget_transactions (category_id);
CREATE INDEX ix_budget_transactions_transfer ON budget_transactions (transfer_id);

-- Rent, subscriptions, pocket money: booked automatically once due. With a
-- target budget set it books a transfer instead of a plain transaction.
CREATE TABLE budget_recurring (
	id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT UNIQUE,
	budget_id INTEGER NOT NULL,
	category_id INTEGER NULL,
	target_budget_id INTEGER NULL,
	target_category_id INTEGER NULL,
	name TEXT NOT NULL,
	amount INTEGER NOT NULL,
	payee TEXT NULL,
	note TEXT NULL,
	interval_months INTEGER NOT NULL DEFAULT 1 CHECK(interval_months IN (1, 3, 6, 12)),
	day_of_month INTEGER NOT NULL CHECK(day_of_month BETWEEN 1 AND 31),
	start_date TEXT NOT NULL,
	end_date TEXT NULL,
	next_due_date TEXT NOT NULL,
	active TINYINT NOT NULL DEFAULT 1 CHECK(active IN (0, 1)),
	row_created_timestamp DATETIME DEFAULT (datetime('now', 'localtime'))
);

-- Seeing the budgets needs its own permission. Administrators have it through
-- the hierarchy, everyone else has to be given it in the user management.
INSERT INTO permission_hierarchy
	(name, parent)
VALUES
	('BUDGET', (SELECT id FROM permission_hierarchy WHERE name = 'ADMIN'));
