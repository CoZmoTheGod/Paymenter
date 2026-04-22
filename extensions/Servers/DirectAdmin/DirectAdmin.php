<?php

namespace Paymenter\Extensions\Servers\DirectAdmin;

use App\Classes\Extension\Server;
use App\Models\Service;
use App\Rules\Domain;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DirectAdmin extends Server
{
    // -------------------------------------------------------------------------
    // HTTP transport
    // -------------------------------------------------------------------------

    private function request($endpoint, $method = 'get', $data = [], $parse = false)
    {
        $host = rtrim($this->config('host'), '/');
        $url = $host . $endpoint;

        $response = Http::withBasicAuth(
            $this->config('username'),
            $this->config('password')
        )->withHeaders([
            'Content-Type' => 'application/json',
        ])->$method($url, $data)->throw();

        if ($parse) {
            return $this->parse($response);
        }

        return $response;
    }

    private function parse($response)
    {
        $body = html_entity_decode($response->body());
        parse_str($body, $parsed);
        if (isset($parsed['list']) && is_array($parsed['list'])) {
            return $parsed['list'];
        }

        return $parsed;
    }

    // -------------------------------------------------------------------------
    // Extension configuration
    // -------------------------------------------------------------------------

    /**
     * Get all the configuration for the extension
     *
     * @param  array  $values
     */
    public function getConfig($values = []): array
    {
        return [
            [
                'name' => 'host',
                'label' => 'DirectAdmin URL',
                'type' => 'text',
                'required' => true,
                'validation' => 'url',
            ],
            [
                'name' => 'username',
                'label' => 'DirectAdmin User name',
                'type' => 'text',
                'required' => true,
            ],
            [
                'name' => 'password',
                'label' => 'DirectAdmin Password',
                'type' => 'password',
                'required' => true,
                'encrypted' => true,
            ],
        ];
    }

    /**
     * Get product config
     *
     * @param  array  $values
     */
    public function getProductConfig($values = []): array
    {
        $upackages = $this->request('/CMD_API_PACKAGES_USER', parse: true);
        $upackages = array_map(function ($package) {
            return ['label' => $package, 'value' => $package];
        }, $upackages);

        try {
            $rpackages = $this->request('/CMD_API_PACKAGES_RESELLER', parse: true);
            $rpackages = array_map(function ($package) {
                return ['label' => $package . ' (reseller)', 'value' => $package];
            }, $rpackages);
        } catch (Exception $e) {
            $rpackages = [];
        }

        $packages = array_merge($upackages, $rpackages);

        $ips = $this->request('/CMD_API_SHOW_RESELLER_IPS', parse: true);

        return [
            [
                'name' => 'package',
                'type' => 'select',
                'label' => 'Package',
                'required' => false,
                'description' => 'Base/fallback DirectAdmin package. If a "storage" configurable option is present on the service, the extension will use DirectAdmin\'s custom-limits mode and this package is informational only.',
                'options' => $packages,
            ],
            [
                'name' => 'ip',
                'type' => 'select',
                'label' => 'IP Address',
                'required' => false,
                'options' => $ips,
            ],
        ];
    }

    public function getCheckoutConfig()
    {
        return [
            [
                'name' => 'domain',
                'type' => 'text',
                'label' => 'Domain',
                'required' => true,
                'validation' => [new Domain, 'required'],
                'placeholder' => 'domain.com',
            ],
        ];
    }

    /**
     * Check if current configuration is valid
     */
    public function testConfig(): bool|string
    {
        try {
            $this->request('/CMD_API_SHOW_USERS')->body();

            return true;
        } catch (Exception $e) {
            return $e->getMessage();
        }
    }

    // -------------------------------------------------------------------------
    // Customer-level property helpers (server-scoped keys)
    //
    // Each customer can theoretically have services on multiple DA servers.
    // We namespace properties by server ID to avoid cross-server collisions.
    // -------------------------------------------------------------------------

    /**
     * Return the scoped property key for a customer-level DA property.
     */
    private function customerPropKey(Service $service, string $name): string
    {
        $serverId = $service->product->server_id;

        return 'directadmin_' . $name . '_' . $serverId;
    }

    /**
     * Read a customer-level property from the user's properties table.
     */
    private function getCustomerProperty(Service $service, string $key): ?string
    {
        $fullKey = $this->customerPropKey($service, $key);

        return $service->user->properties()->where('key', $fullKey)->first()?->value;
    }

    /**
     * Write a customer-level property to the user's properties table.
     */
    private function setCustomerProperty(Service $service, string $key, string $value): void
    {
        $fullKey = $this->customerPropKey($service, $key);

        $service->user->properties()->updateOrCreate(
            ['key' => $fullKey],
            ['name' => 'DirectAdmin ' . $key, 'value' => $value]
        );
    }

    /**
     * Remove a customer-level property from the user's properties table.
     */
    private function deleteCustomerProperty(Service $service, string $key): void
    {
        $fullKey = $this->customerPropKey($service, $key);

        $service->user->properties()->where('key', $fullKey)->delete();
    }

    // -------------------------------------------------------------------------
    // Username derivation
    // -------------------------------------------------------------------------

    /**
     * Derive a DirectAdmin username from a customer email address.
     *
     * Rules applied in order:
     *  1. Take the local part (before @), lowercase it.
     *  2. Strip any character that is not [a-z0-9].
     *  3. Empty result → fallback 'user' + 6 random lowercase chars.
     *  4. First char is a digit → prefix 'u'.
     *  5. Truncate base to 14 chars (reserves room for a 2-digit collision suffix).
     *  6. Collision-check via GET /CMD_API_SHOW_USERS; append numeric suffix 1–99,
     *     then 3 random lowercase alphanum chars as last resort.
     *  7. Return unique username (always ≤ 16 chars).
     *
     * Collisions are only relevant for DIFFERENT customers that happen to map to
     * the same base name.  The same customer reuses the stored username and never
     * reaches this method.
     */
    private function generateUsernameFromEmail(string $email): string
    {
        $local = strtolower(explode('@', $email)[0]);
        $base  = preg_replace('/[^a-z0-9]/', '', $local);

        if ($base === '') {
            $base = 'user' . strtolower(Str::random(6));
        }

        if (isset($base[0]) && ctype_digit($base[0])) {
            $base = 'u' . $base;
        }

        // Truncate to 14 to leave room for up to a 2-digit suffix within the 16-char limit.
        $base = substr($base, 0, 14);

        try {
            $existing = $this->request('/CMD_API_SHOW_USERS', parse: true);
            if (!is_array($existing)) {
                $existing = [];
            }
        } catch (Exception $e) {
            $existing = [];
        }

        $candidate = $base;

        if (in_array($candidate, $existing, true)) {
            $found = false;
            for ($i = 1; $i <= 99; $i++) {
                $candidate = $base . $i;
                if (!in_array($candidate, $existing, true)) {
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                // Last resort: re-truncate base to 13 so base(13) + suffix(3) = 16 chars max.
                $candidate = substr($base, 0, 13) . strtolower(Str::random(3));
            }
        }

        return $candidate;
    }

    // -------------------------------------------------------------------------
    // DA user provisioning helper
    // -------------------------------------------------------------------------

    /**
     * Return the existing DA user for this customer or signal that a new one
     * must be created.
     *
     * @return array{username: string, password: string, isNew: bool}
     */
    private function getOrCreateDaUser(Service $service): array
    {
        $username = $this->getCustomerProperty($service, 'username');

        if ($username) {
            $password = $this->getCustomerProperty($service, 'password');

            return ['username' => $username, 'password' => $password, 'isNew' => false];
        }

        $username = $this->generateUsernameFromEmail($service->user->email);
        $password = substr(
            str_shuffle(
                str_repeat(
                    $x = 'abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ(!@.$%',
                    (int) ceil(12 / strlen($x))
                )
            ),
            0,
            12
        );

        return ['username' => $username, 'password' => $password, 'isNew' => true];
    }

    // -------------------------------------------------------------------------
    // Sibling-service queries and option helpers
    // -------------------------------------------------------------------------

    /**
     * Return all OTHER active/pending/suspended services for this customer on
     * the same DA server extension, excluding the given service itself.
     */
    private function getCustomerActiveServices(Service $service): Collection
    {
        $serverId = $service->product->server_id;

        return Service::where('user_id', $service->user_id)
            ->where('id', '!=', $service->id)
            ->whereIn('status', [
                Service::STATUS_ACTIVE,
                Service::STATUS_PENDING,
                Service::STATUS_SUSPENDED,
            ])
            ->whereHas('product', function ($q) use ($serverId) {
                $q->where('server_id', $serverId);
            })
            ->with(['configs.configOption', 'configs.configValue', 'properties'])
            ->get();
    }

    /**
     * Extract the relevant configurable-option values from a service's configs.
     *
     * Returns an array with keys matching the env_variable names of the options
     * (e.g. 'storage', 'bandwidth', 'wordpress').
     */
    private function getServiceOptions(Service $service): array
    {
        $options = [];

        foreach ($service->configs as $config) {
            if ($config->configOption && $config->configValue) {
                $options[$config->configOption->env_variable] =
                    $config->configValue->env_variable ?? $config->configValue->name;
            }
        }

        return $options;
    }

    // -------------------------------------------------------------------------
    // Totals computation
    // -------------------------------------------------------------------------

    /**
     * Compute the aggregate resource totals for the DA user that owns this
     * service, optionally excluding one service (e.g. during termination) and
     * optionally overriding the properties for the current service (e.g. during
     * an upgrade where new values have not been persisted yet).
     *
     * @return array{storage: int, bandwidth: int|string, vdomains: int, wordpress: bool}
     */
    private function computeTotals(
        Service $service,
        ?Service $excluding = null,
        ?array $overrideProperties = null
    ): array {
        $storage            = 0;
        $bandwidth          = 0;
        $bandwidthUnlimited = false;
        $wordpress          = false;
        $vdomains           = 0;

        // Accumulate a single service's contribution into the running totals.
        $accumulate = function (array $opts) use (
            &$storage,
            &$bandwidth,
            &$bandwidthUnlimited,
            &$wordpress,
            &$vdomains
        ): void {
            $storage += (int) ($opts['storage'] ?? 0);

            if (empty($opts['bandwidth'])) {
                $bandwidthUnlimited = true;
            } else {
                $bandwidth += (int) $opts['bandwidth'];
            }

            if (!empty($opts['wordpress']) && $opts['wordpress'] !== '0') {
                $wordpress = true;
            }

            $vdomains++;
        };

        // Include the current service (unless it is the one being excluded).
        if ($excluding === null || $excluding->id !== $service->id) {
            $opts = $overrideProperties !== null
                ? $overrideProperties
                : $this->getServiceOptions($service);
            $accumulate($opts);
        }

        // Include sibling services.
        foreach ($this->getCustomerActiveServices($service) as $sibling) {
            if ($excluding !== null && $sibling->id === $excluding->id) {
                continue;
            }
            $accumulate($this->getServiceOptions($sibling));
        }

        return [
            'storage'   => $storage,
            'bandwidth' => $bandwidthUnlimited ? 'unlimited' : $bandwidth,
            'vdomains'  => $vdomains,
            'wordpress' => $wordpress,
        ];
    }

    // -------------------------------------------------------------------------
    // DA API limit payload builder
    // -------------------------------------------------------------------------

    /**
     * Build the DirectAdmin custom-limits payload from pre-computed totals.
     *
     * Used by both createServer() (via CMD_API_ACCOUNT_USER) and
     * pushUserLimits() (via CMD_API_MODIFY_USER).
     */
    private function buildCustomLimitsPayload(array $totals): array
    {
        return [
            'custom'      => 'yes',
            'quota'       => $totals['storage'],
            'bandwidth'   => $totals['bandwidth'],
            'vdomains'    => $totals['vdomains'],
            'domainptr'   => 0,
            'nsubdomains' => 'unlimited',
            'nemails'     => 'unlimited',
            'nemailf'     => 'unlimited',
            'nemailml'    => 'unlimited',
            'nemailr'     => 'unlimited',
            'mysql'       => 'unlimited',
            'ftp'         => 'unlimited',
            'aftp'        => 'ON',
            'cgi'         => 'ON',
            'php'         => 'ON',
            'spam'        => 'ON',
            'cron'        => 'ON',
            'catchall'    => 'ON',
            'ssl'         => 'ON',
            'ssh'         => 'OFF',
            'sysinfo'     => 'ON',
            'dnscontrol'  => 'ON',
            'wordpress'   => $totals['wordpress'] ? 'ON' : 'OFF',
        ];
    }

    // -------------------------------------------------------------------------
    // DA API wrappers
    // -------------------------------------------------------------------------

    /**
     * Push updated resource limits to an existing DA user.
     */
    private function pushUserLimits(string $username, array $totals): void
    {
        $response = $this->request('/CMD_API_MODIFY_USER', 'post', array_merge([
            'action' => 'customize',
            'user'   => $username,
        ], $this->buildCustomLimitsPayload($totals)), parse: true);

        if ($response['error'] != '0') {
            throw new Exception('Error updating DirectAdmin user limits: ' . $response['text']);
        }
    }

    /**
     * Add a domain to an existing DA user's account.
     */
    private function addDomainToUser(string $username, string $domain): void
    {
        $response = $this->request('/CMD_API_DOMAIN', 'post', [
            'action'     => 'create',
            'domain'     => $domain,
            'ubandwidth' => 'ON',
            'uquota'     => 'ON',
            'user'       => $username,
        ], parse: true);

        if ($response['error'] != '0') {
            throw new Exception('Error adding domain to DirectAdmin user: ' . $response['text']);
        }
    }

    /**
     * Remove a domain from a DA user's account.
     */
    private function removeDomainFromUser(string $username, string $domain): void
    {
        $response = $this->request('/CMD_API_DOMAIN', 'post', [
            'action'  => 'delete',
            'select0' => $domain,
            'user'    => $username,
        ], parse: true);

        if ($response['error'] != '0') {
            throw new Exception('Error removing domain from DirectAdmin user: ' . $response['text']);
        }
    }

    /**
     * Suspend a single domain on a DA user's account.
     */
    private function suspendDomain(string $username, string $domain): void
    {
        $response = $this->request('/CMD_API_DOMAIN', 'post', [
            'action' => 'suspend',
            'domain' => $domain,
            'user'   => $username,
        ], parse: true);

        if ($response['error'] != '0') {
            throw new Exception('Error suspending DirectAdmin domain: ' . $response['text']);
        }
    }

    /**
     * Unsuspend a single domain on a DA user's account.
     */
    private function unsuspendDomain(string $username, string $domain): void
    {
        $response = $this->request('/CMD_API_DOMAIN', 'post', [
            'action' => 'unsuspend',
            'domain' => $domain,
            'user'   => $username,
        ], parse: true);

        if ($response['error'] != '0') {
            throw new Exception('Error unsuspending DirectAdmin domain: ' . $response['text']);
        }
    }

    /**
     * Promote a domain to be the primary domain of a DA user.
     *
     * @param  string  $username         DA username
     * @param  string  $currentPrimary   The current primary domain being replaced
     * @param  string  $newPrimaryDomain The new primary domain to promote
     */
    private function promoteDomainToPrimary(string $username, string $currentPrimary, string $newPrimaryDomain): void
    {
        $response = $this->request('/CMD_API_CHANGE_DOMAIN', 'post', [
            'old_domain' => $currentPrimary,
            'new_domain' => $newPrimaryDomain,
            'user'       => $username,
        ], parse: true);

        if ($response['error'] != '0') {
            throw new Exception('Error promoting domain to primary in DirectAdmin: ' . $response['text']);
        }
    }

    /**
     * Delete a DA user and all their data.
     */
    private function deleteDaUser(string $username): void
    {
        $response = $this->request('/CMD_API_SELECT_USERS', 'post', [
            'confirmed' => 'Confirm',
            'delete'    => 'yes',
            'select0'   => $username,
        ], parse: true);

        if ($response['error'] != '0') {
            throw new Exception('Error deleting DirectAdmin user: ' . $response['text']);
        }
    }

    // -------------------------------------------------------------------------
    // Main extension methods
    // -------------------------------------------------------------------------

    /**
     * Create (or extend) a DirectAdmin hosting account for a service.
     *
     * Model: one DA user per Paymenter customer. Each service adds one domain.
     *
     * @param  array  $settings    (product settings)
     * @param  array  $properties  (checkout options / configurable option values)
     * @return array
     */
    public function createServer(Service $service, $settings, $properties): array
    {
        $domain = $properties['domain'] ?? '';

        if (isset($settings['ip'])) {
            $ip = $settings['ip'];
        } else {
            $ip = $this->request('/CMD_API_SHOW_RESELLER_IPS', parse: true)[0] ?? null;

            if (!$ip) {
                throw new Exception('No IP address available for the server');
            }
        }

        $daUser = $this->getOrCreateDaUser($service);
        $username = $daUser['username'];
        $password = $daUser['password'];

        if ($daUser['isNew']) {
            // -----------------------------------------------------------------
            // First service for this customer: create the DA user account.
            // -----------------------------------------------------------------
            $totals  = $this->computeTotals($service, null, $properties);
            $payload = array_merge([
                'action'  => 'create',
                'add'     => 'Submit',
                'username' => $username,
                'email'    => $service->user->email,
                'passwd'   => $password,
                'passwd2'  => $password,
                'ip'       => $ip,
                'domain'   => $domain,
                'notify'   => 'yes',
            ], $this->buildCustomLimitsPayload($totals));

            // If no storage option is present, fall back to a named package.
            if (($totals['storage'] === 0) && empty($properties['storage'])) {
                if (empty($settings['package'])) {
                    throw new Exception('No package configured and no storage configurable option provided');
                }
                // Replace custom-limits keys with package name.
                $payload = array_diff_key($payload, array_flip([
                    'custom', 'quota', 'bandwidth', 'vdomains', 'domainptr',
                    'nsubdomains', 'nemails', 'nemailf', 'nemailml', 'nemailr',
                    'mysql', 'ftp', 'aftp', 'cgi', 'php', 'spam', 'cron',
                    'catchall', 'ssl', 'ssh', 'sysinfo', 'dnscontrol', 'wordpress',
                ]));
                $payload['package'] = $settings['package'];
            }

            $response = $this->request('/CMD_API_ACCOUNT_USER', 'post', $payload, parse: true);

            if ($response['error'] != '0') {
                throw new Exception('Error creating DirectAdmin account: ' . $response['text']);
            }

            // Persist customer-level properties.
            $this->setCustomerProperty($service, 'username', $username);
            $this->setCustomerProperty($service, 'password', $password);
            $this->setCustomerProperty($service, 'primary_domain', $domain);
        } else {
            // -----------------------------------------------------------------
            // Subsequent service: add a domain to the existing DA user.
            // -----------------------------------------------------------------
            try {
                $this->addDomainToUser($username, $domain);
            } catch (Exception $e) {
                Log::error('DirectAdmin createServer: failed to add domain', [
                    'username' => $username,
                    'domain'   => $domain,
                    'error'    => $e->getMessage(),
                ]);
                throw $e;
            }

            try {
                $totals = $this->computeTotals($service, null, $properties);
                $this->pushUserLimits($username, $totals);
            } catch (Exception $e) {
                Log::error('DirectAdmin createServer: failed to push limits after adding domain', [
                    'username' => $username,
                    'domain'   => $domain,
                    'error'    => $e->getMessage(),
                ]);
                throw $e;
            }
        }

        // Persist per-service domain property.
        $service->properties()->updateOrCreate(
            ['key' => 'directadmin_domain'],
            ['name' => 'DirectAdmin domain', 'value' => $domain]
        );

        return [
            'username' => $username,
            'password' => $password,
            'domain'   => $domain,
            'ip'       => $ip,
        ];
    }

    /**
     * Suspend a single service domain in DirectAdmin.
     *
     * Only the domain that belongs to this service is suspended; other services
     * for the same customer continue to operate.
     *
     * @param  array  $settings    (product settings)
     * @param  array  $properties  (service properties / configurable option values)
     * @return bool
     */
    public function suspendServer(Service $service, $settings, $properties): bool
    {
        $username = $this->getCustomerProperty($service, 'username');

        if (!$username) {
            throw new Exception('Service has not been created');
        }

        $domain = $properties['directadmin_domain']
            ?? $service->properties()->where('key', 'directadmin_domain')->first()?->value;

        if ($domain) {
            $this->suspendDomain($username, $domain);
        } else {
            // Legacy fallback: suspend the whole user account.
            $response = $this->request('/CMD_API_SELECT_USERS', 'post', [
                'location' => 'CMD_SELECT_USERS',
                'suspend'  => 'suspend',
                'select0'  => $username,
            ], parse: true);

            if ($response['error'] != '0') {
                throw new Exception('Error suspending DirectAdmin account: ' . $response['text']);
            }
        }

        return true;
    }

    /**
     * Unsuspend a single service domain in DirectAdmin.
     *
     * @param  array  $settings    (product settings)
     * @param  array  $properties  (service properties / configurable option values)
     * @return bool
     */
    public function unsuspendServer(Service $service, $settings, $properties): bool
    {
        $username = $this->getCustomerProperty($service, 'username');

        if (!$username) {
            throw new Exception('Service has not been created');
        }

        $domain = $properties['directadmin_domain']
            ?? $service->properties()->where('key', 'directadmin_domain')->first()?->value;

        if ($domain) {
            $this->unsuspendDomain($username, $domain);
        } else {
            // Legacy fallback: unsuspend the whole user account.
            $response = $this->request('/CMD_API_SELECT_USERS', 'post', [
                'location' => 'CMD_SELECT_USERS',
                'suspend'  => 'unsuspend',
                'select0'  => $username,
            ], parse: true);

            if ($response['error'] != '0') {
                throw new Exception('Error unsuspending DirectAdmin account: ' . $response['text']);
            }
        }

        return true;
    }

    /**
     * Terminate a service by removing its domain from the DA user.
     *
     * If this is the last service for the customer, the DA user account is
     * deleted entirely and all customer-level properties are cleared.
     *
     * @param  array  $settings    (product settings)
     * @param  array  $properties  (service properties / configurable option values)
     * @return bool
     */
    public function terminateServer(Service $service, $settings, $properties): bool
    {
        $username = $this->getCustomerProperty($service, 'username');

        if (!$username) {
            throw new Exception('Service has not been created');
        }

        $domain = $properties['directadmin_domain']
            ?? $service->properties()->where('key', 'directadmin_domain')->first()?->value;

        $siblings = $this->getCustomerActiveServices($service);

        if ($siblings->isEmpty()) {
            // ----------------------------------------------------------------
            // Last service — delete the entire DA user account.
            // ----------------------------------------------------------------
            $this->deleteDaUser($username);

            $this->deleteCustomerProperty($service, 'username');
            $this->deleteCustomerProperty($service, 'password');
            $this->deleteCustomerProperty($service, 'primary_domain');
        } else {
            // ----------------------------------------------------------------
            // Other services remain — remove only this domain.
            // ----------------------------------------------------------------
            $primaryDomain = $this->getCustomerProperty($service, 'primary_domain');

            // If we are removing the primary domain, promote another one first.
            if ($domain && $domain === $primaryDomain) {
                $newPrimary = null;

                foreach ($siblings as $sibling) {
                    $sibDomain = $sibling->properties
                        ->firstWhere('key', 'directadmin_domain')
                        ?->value;
                    if ($sibDomain && $sibDomain !== $domain) {
                        $newPrimary = $sibDomain;
                        break;
                    }
                }

                if ($newPrimary) {
                    try {
                        $this->promoteDomainToPrimary($username, $domain, $newPrimary);
                        $this->setCustomerProperty($service, 'primary_domain', $newPrimary);
                    } catch (Exception $e) {
                        Log::error('DirectAdmin terminateServer: failed to promote new primary domain', [
                            'username'   => $username,
                            'oldPrimary' => $domain,
                            'newPrimary' => $newPrimary,
                            'error'      => $e->getMessage(),
                        ]);
                        throw $e;
                    }
                }
            }

            if ($domain) {
                try {
                    $this->removeDomainFromUser($username, $domain);
                } catch (Exception $e) {
                    Log::error('DirectAdmin terminateServer: failed to remove domain', [
                        'username' => $username,
                        'domain'   => $domain,
                        'error'    => $e->getMessage(),
                    ]);
                    throw $e;
                }
            }

            try {
                $totals = $this->computeTotals($service, $service);
                $this->pushUserLimits($username, $totals);
            } catch (Exception $e) {
                Log::error('DirectAdmin terminateServer: failed to push updated limits', [
                    'username' => $username,
                    'error'    => $e->getMessage(),
                ]);
                throw $e;
            }
        }

        // Remove the per-service domain property.
        $service->properties()->where('key', 'directadmin_domain')->delete();

        return true;
    }

    /**
     * Upgrade resource limits for a service.
     *
     * Recomputes aggregate totals from all active services (including the
     * newly-upgraded one) and pushes them to the shared DA user.
     *
     * @param  array  $settings    (product settings)
     * @param  array  $properties  (new configurable option values)
     * @return bool
     */
    public function upgradeServer(Service $service, $settings, $properties): bool
    {
        $username = $this->getCustomerProperty($service, 'username');

        if (!$username) {
            throw new Exception('Service has not been created');
        }

        $totals = $this->computeTotals($service, null, $properties);

        $this->pushUserLimits($username, $totals);

        return true;
    }

    // -------------------------------------------------------------------------
    // Customer-facing actions
    // -------------------------------------------------------------------------

    public function getActions(Service $service, $settings, $properties): array
    {
        $username = $this->getCustomerProperty($service, 'username');
        $password = $this->getCustomerProperty($service, 'password');

        if (!$username || !$password) {
            return [];
        }

        return [
            [
                'label'    => 'Access DirectAdmin',
                'type'     => 'button',
                'function' => 'ssoLink',
            ],
        ];
    }

    public function ssoLink(Service $service, $settings, $properties): string
    {
        $username = $this->getCustomerProperty($service, 'username');
        $password = $this->getCustomerProperty($service, 'password');

        if (!$username || !$password) {
            return '';
        }

        $response = Http::withBasicAuth($username, $password)
            ->post(rtrim($this->config('host'), '/') . '/CMD_API_LOGIN_KEYS', [
                'action' => 'create',
                'type'   => 'one_time_url',
                'expiry' => '5m',
            ])->throw();

        $response = $this->parse($response);

        if (!isset($response['error'])) {
            throw new Exception('Unexpected DirectAdmin response while creating SSO link');
        }

        if ($response['error'] != '0') {
            throw new Exception('Error creating DirectAdmin SSO link: ' . ($response['text'] ?? 'Unknown error'));
        }

        return $response['details'] ?? '';
    }
}
