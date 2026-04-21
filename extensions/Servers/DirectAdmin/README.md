# DirectAdmin Extension for Paymenter

Provisions and manages DirectAdmin web-hosting accounts from within the Paymenter billing system.

---

## Extension settings

| Setting | Description |
|---|---|
| **DirectAdmin URL** | Full URL to your DirectAdmin control panel (e.g. `https://da.example.com:2222`). |
| **DirectAdmin User name** | Reseller or admin account used by Paymenter to create user accounts. |
| **DirectAdmin Password** | Password for the reseller/admin account above (stored encrypted). |

---

## Product settings

| Setting | Description |
|---|---|
| **Package** | Base DirectAdmin package. When the `storage` configurable option is present on a service this field is informational only — DirectAdmin's custom-limits mode is used instead. |
| **IP Address** | Default IP to assign to new accounts. Leave blank to auto-select the first available reseller IP. |

---

## Configurable options

Attach a configurable-option group to a product to let customers choose resources at checkout. The extension reads options **by exact key name** from `$properties`.

| Option key | Type | Example values | Notes |
|---|---|---|---|
| `storage` | Radio / Select | `5000`, `10000`, `15000` | Raw MB value — label can be anything (e.g. "5 GB"). When present, custom-limits mode is used. |
| `bandwidth` | Radio / Select / Hidden | `100000` | Raw MB. Omit or set to `0` for unlimited. |
| `wordpress` | Checkbox | `0` / `1` | **Billing only** — see section below. |

### Storage tiers (example setup)

```
Group: "Web Hosting Resources"  → attached to your DirectAdmin product

Options:
  storage  (Radio)
    5000  → "5 GB"
    10000 → "10 GB"
    15000 → "15 GB"
```

When `storage` is set the account is created with `custom=yes` and the inline quota value. If the customer later upgrades (selects a higher tier), Paymenter calls `CMD_API_MODIFY_USER` to resize the live account in real-time — no new account is created.

---

## WordPress configurable option (billing only)

A `wordpress` checkbox option can be added to your configurable-option group for billing purposes. When a customer selects it they are **charged** for the WordPress add-on, but **WordPress is not installed automatically**.

The customer (or you as the administrator) installs WordPress manually through DirectAdmin's built-in auto-installer panel:

1. Log into DirectAdmin as the hosting user.
2. Open **Installatron** or **Softaculous** from the user panel.
3. Install WordPress in one click — credentials, database, and file permissions are all configured automatically by the installer.

This keeps the extension lean and avoids hard-coding assumptions about which installer your server uses.

---

## Username derivation

DirectAdmin usernames are derived deterministically from the customer's email address so accounts are recognisable without looking them up in Paymenter.

**Algorithm (applied in order):**

1. Take the local part of the email (everything before `@`) and lowercase it.
2. Strip every character that is not `[a-z0-9]` (removes dots, plus-tags, dashes, underscores). DirectAdmin usernames must be alphanumeric.
3. If the result is empty after stripping, fall back to `user` followed by 6 random lowercase alphanumeric characters.
4. If the first character is a digit (DirectAdmin rejects usernames starting with `0–9`), prefix `u`.
5. Truncate the base to **14 characters** to leave headroom for a 2-digit collision suffix.
6. **Max length is 16 characters** (DirectAdmin default since v1.693).
7. **Collision check**: existing usernames are fetched via `GET /CMD_API_SHOW_USERS`. If the candidate already exists, a numeric suffix (`1`–`99`) is appended until a unique name is found. If all 99 suffixes are taken, 3 random lowercase alphanumeric characters are appended instead.

**Examples:**

| Email | Derived username |
|---|---|
| `alice.smith@example.com` | `alicesmith` |
| `Bob+hosting@example.com` | `bobhosting` |
| `123go@example.com` | `u123go` |
| Second order from `alice.smith@example.com` | `alicesmith1` |

---

## Testing checklist

1. Clear Paymenter caches:
   ```bash
   php artisan optimize:clear
   ```
2. Verify the `wp_installer` field is **gone** from the DirectAdmin extension settings UI.
3. Order with email `test.user@example.com` → DA user should be `testuser`.
4. Second order from the same email → `testuser1`.
5. Order with email `123@example.com` → `u123`.
6. Confirm the WordPress checkbox on checkout results in a charge but **does not** install WordPress — the customer installs it manually via Installatron/Softaculous in the user panel.

---

## Notes

- This extension depends on the **configurable-options** changes introduced in the `feat/directadmin-configurable-options` PR. Ensure that PR is merged before deploying this one.
- The `upgrade()` method calls `CMD_API_MODIFY_USER` with `action=customize` to resize live accounts — suspension, unsuspension, and termination are unaffected.
