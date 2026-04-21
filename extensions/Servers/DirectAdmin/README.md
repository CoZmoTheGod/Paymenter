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
| `wordpress` | Checkbox | `0` / `1` | Toggles DirectAdmin's built-in WordPress Manager feature flag for the user. |

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

**Each DirectAdmin account created through this extension is limited to 1 primary domain and 0 domain aliases. Subdomains under the primary domain are unlimited.**

---

## WordPress Manager configurable option

The `wordpress` configurable option toggles DirectAdmin's built-in **WordPress Manager** for the user. When checked, the user sees WordPress Manager in their DA panel and can install/manage WP sites themselves. When unchecked, the menu is hidden (existing installs are NOT removed — DA just hides the UI).

- `wordpress=ON` — WordPress Manager menu is visible in the user's DirectAdmin panel.
- `wordpress=OFF` — WordPress Manager menu is hidden (no sites are deleted).

Changing the checkbox on an existing service via Paymenter's upgrade flow re-pushes the `wordpress=ON/OFF` flag to DirectAdmin immediately.

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
2. Order with storage 10 GB, WordPress **unchecked** → DA user should have `Domains limit = 1`, no WordPress Manager in menu.
3. Order with storage 10 GB, WordPress **checked** → DA user should have `Domains limit = 1`, WordPress Manager visible under Advanced Features.
4. On the first service, toggle the WordPress checkbox on via upgrade → DA user's WordPress Manager menu now appears (no data lost).
5. Order with email `test.user@example.com` → DA user should be `testuser`.
6. Second order from the same email → `testuser1`.
7. Order with email `123@example.com` → `u123`.
8. Existing accounts (provisioned before this PR) are NOT retroactively fixed — they keep their `vdomains=unlimited` until manually modified in DA or re-upgraded.

---

## Notes

- This extension depends on the **configurable-options** changes introduced in the `feat/directadmin-configurable-options` PR. Ensure that PR is merged before deploying this one.
- The `upgrade()` method calls `CMD_API_MODIFY_USER` with `action=customize` to resize live accounts — suspension, unsuspension, and termination are unaffected.
