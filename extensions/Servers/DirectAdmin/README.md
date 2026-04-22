# DirectAdmin Extension for Paymenter

Provisions and manages DirectAdmin web-hosting accounts from within the Paymenter billing system.

---

## Account model: one DirectAdmin account per customer

All of a customer's Paymenter services share **one DirectAdmin login**. Each service adds one domain to that account. A customer with three services sees all three domains under a single DA login — not three separate accounts.

| Concept | Description |
|---|---|
| **DA user** | Created once, on the customer's first service. Reused for every subsequent service. |
| **Domain** | Each Paymenter service = one domain on the shared DA user. The first service's domain becomes the DA primary domain. |
| **Quotas** | `quota`, `bandwidth`, and `vdomains` are automatically the **sum** of all the customer's active services. They are recalculated and pushed to DA on every create, upgrade, or termination. |
| **Suspend** | Suspending a service suspends **only that service's domain**. Other domains continue to serve traffic. The customer can still log in and manage their other sites. |
| **Terminate (partial)** | Removing a service deletes only that domain from the DA account and shrinks the aggregate quota/vdomains. |
| **Terminate (last)** | Removing the customer's last service **deletes the DA user entirely** and clears all stored DA properties. |
| **WordPress Manager** | The DA WordPress Manager feature flag is set to `ON` for the account if **any** of the customer's active services has the `wordpress` configurable option checked. When all services have it unchecked the flag is `OFF` (existing WP installs are not deleted — DA just hides the menu). |

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

Attach a configurable-option group to a product to let customers choose resources at checkout. The extension reads options **by exact key name** from the service properties.

| Option key | Type | Example values | Notes |
|---|---|---|---|
| `storage` | Radio / Select | `5000`, `10000`, `15000` | Raw MB — label can be anything (e.g. "5 GB"). When present, custom-limits mode is used. Summed across all the customer's active services. |
| `bandwidth` | Radio / Select / Hidden | `100000` | Raw MB. Omit or set to `0` for unlimited. Summed across active services. |
| `wordpress` | Checkbox | `0` / `1` | Toggles DirectAdmin's built-in **WordPress Manager** feature. `ON` if any active service has this checked. |

### Storage tiers (example setup)

```
Group: "Web Hosting Resources"  → attached to your DirectAdmin product

Options:
  storage  (Radio)
    5000  → "5 GB"
    10000 → "10 GB"
    15000 → "15 GB"
```

When `storage` is set the account is created with `custom=yes` and the inline quota value. If the customer upgrades (selects a higher tier), Paymenter calls `CMD_API_MODIFY_USER` to resize the live account in real-time — no new account is created.

---

## WordPress Manager feature flag

The `wordpress` configurable option toggles DirectAdmin's built-in **WordPress Manager** for the shared DA user.

- When **any** of the customer's active services has the checkbox checked → `wordpress=ON` is sent to DA → the WordPress Manager menu is visible in the DA panel.
- When **all** active services have the checkbox unchecked → `wordpress=OFF` is sent → the menu is hidden.
- Existing WordPress installations are **not** removed — DirectAdmin only shows or hides the UI.
- Toggling the checkbox on an existing service via Paymenter's upgrade flow re-pushes the flag to DA immediately.

---

## Username derivation

DirectAdmin usernames are derived deterministically from the customer's email address. The username is created **once** on first provisioning and reused for all subsequent services.

**Algorithm (applied in order):**

1. Take the local part of the email (everything before `@`) and lowercase it.
2. Strip every character that is not `[a-z0-9]`.
3. If the result is empty, fall back to `user` followed by 6 random lowercase alphanumeric characters.
4. If the first character is a digit (DA rejects usernames starting with `0–9`), prefix `u`.
5. Truncate the base to **14 characters** to leave headroom for a 2-digit collision suffix.
6. **Max length is 16 characters** (DirectAdmin default since v1.693).
7. **Collision check** (new customers only): existing DA usernames are fetched via `GET /CMD_API_SHOW_USERS`. Collisions only occur when two *different* customers happen to produce the same base name. A numeric suffix (`1`–`99`) is appended; if all are taken, 3 random lowercase characters are used instead.

**Examples:**

| Email | Derived username |
|---|---|
| `alice.smith@example.com` | `alicesmith` |
| `Bob+hosting@example.com` | `bobhosting` |
| `123go@example.com` | `u123go` |
| Different customer with same base | `alicesmith1` |
| Same customer, second service | reuses `alicesmith` — no new user |

---

## Stored properties

### Customer-level (stored on the Paymenter user, scoped per DA server)

| Key pattern | Value |
|---|---|
| `directadmin_username_<serverId>` | DA username (shared by all services on this server) |
| `directadmin_password_<serverId>` | DA password (generated once, never rotated) |
| `directadmin_primary_domain_<serverId>` | Current DA primary domain |

### Service-level (stored on each service)

| Key | Value |
|---|---|
| `directadmin_domain` | The domain this specific service owns on the shared DA account |

---

## Testing checklist

1. Clear Paymenter caches:
   ```bash
   php artisan optimize:clear
   ```
2. Order #1 for `alice@example.com`, domain `site1.com`, 10 GB, WP checked. → DA user `alice` created, primary domain `site1.com`, quota 10000, vdomains=1, WordPress Manager ON.
3. Order #2 for **same** customer, domain `site2.com`, 5 GB, WP unchecked. → Same DA user `alice`, now 2 domains, quota 15000, vdomains=2, WP still ON (service #1 still has it).
4. Upgrade service #1 storage 10→15 GB. → DA quota now 20000 (15+5), vdomains=2.
5. Toggle service #1 WP off. → Both services WP off → `wordpress=OFF`. Re-check service #1 → `wordpress=ON`.
6. Suspend service #2. → `site2.com` suspended, `site1.com` still serving. Customer can still log into DA.
7. Unsuspend service #2. → domain active again.
8. Terminate service #2. → `site2.com` removed, quota back to 15000, vdomains=1. User `alice` still exists.
9. Terminate service #1 (last). → DA user `alice` deleted entirely. Customer's DA properties cleared.
10. Customer orders again after full termination. → new DA user `alice` created fresh (password regenerated).
11. Edge case: terminate the service owning the primary domain while others exist. → one remaining domain is promoted to primary via `CMD_API_CHANGE_DOMAIN`, customer's `directadmin_primary_domain` property updated.

---

## Migration note for existing accounts

Existing DA accounts provisioned under the old per-service model (one DA user per service) are **not automatically migrated**. Options:

- **Manual consolidation**: Move the extra domains into a single DA account manually, then update the Paymenter service properties (`directadmin_domain` on each service, `directadmin_username_<id>` / `directadmin_password_<id>` / `directadmin_primary_domain_<id>` on the user).
- **Natural expiry**: Let old accounts run until they are naturally terminated, at which point the extension deletes only the DA user associated with that last service.

---

## Notes

- The `domain` field on the order form is the domain added to that customer's DA account for that service.
- Upgrading a service calls `CMD_API_MODIFY_USER` with `action=customize` to resize the shared account in real-time.
- Each customer may theoretically have services on multiple DA server extension instances; properties are scoped by server ID to avoid cross-server collisions.
