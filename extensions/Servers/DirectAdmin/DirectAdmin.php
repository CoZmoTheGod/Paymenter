<?php

namespace Paymenter\Extensions\Servers\DirectAdmin;

use App\Classes\Extension\Server;
use App\Models\Service;
use App\Rules\Domain;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class DirectAdmin extends Server
{
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
        // Map to label => value pairs
        $upackages = array_map(function ($package) {
            return ['label' => $package, 'value' => $package];
        }, $upackages);

        try {
            // If you are a reseller you won't have access to reseller packages
            $rpackages = $this->request('/CMD_API_PACKAGES_RESELLER', parse: true);
            // Merge user packages with reseller packages
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
     * Check if currenct configuration is valid
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

    /**
     * Create a server
     *
     * @param  array  $settings  (product settings)
     * @param  array  $properties  (checkout options / configurable option values)
     * @return array
     */
    public function createServer(Service $service, $settings, $properties)
    {
        $password = substr(str_shuffle(str_repeat($x = 'abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ(!@.$%', ceil(10 / strlen($x)))), 1, 12);
        $username = $this->generateUsernameFromEmail($service->user->email);
        $settings = array_merge($settings, $properties);

        // Read configurable option values (keyed by option name in $properties)
        $storage   = $properties['storage'] ?? null;
        $bandwidth = $properties['bandwidth'] ?? null;

        if (isset($settings['ip'])) {
            $ip = $settings['ip'];
        } else {
            $ip = $this->request('/CMD_API_SHOW_RESELLER_IPS', parse: true)[0] ?? null;

            if (!$ip) {
                throw new Exception('No IP address available for the server');
            }
        }

        $payload = [
            'action'   => 'create',
            'add'      => 'Submit',
            'username' => $username,
            'email'    => $service->user->email,
            'passwd'   => $password,
            'passwd2'  => $password,
            'ip'       => $ip,
            'domain'   => $properties['domain'] ?? '',
            'notify'   => 'yes',
        ];

        if ($storage !== null) {
            // Custom-limits mode: send inline resource limits instead of a named package.
            // Even if a package is configured in product settings it is used only as a label/fallback reference;
            // DirectAdmin custom= yes overrides it completely.
            $payload = array_merge($payload, $this->buildCustomLimitsPayload((int) $storage, $bandwidth));
        } else {
            // Fallback: use the named package configured on the product.
            if (empty($settings['package'])) {
                throw new Exception('No package configured and no storage configurable option provided');
            }
            $payload['package'] = $settings['package'];
        }

        $response = $this->request('/CMD_API_ACCOUNT_USER', 'post', $payload, parse: true);

        if ($response['error'] != '0') {
            throw new Exception('Error creating DirectAdmin account: ' . $response['text']);
        }

        $service->properties()->updateOrCreate([
            'key' => 'directadmin_username',
        ], [
            'name' => 'DirectAdmin username',
            'value' => $username,
        ]);

        $service->properties()->updateOrCreate([
            'key' => 'directadmin_password',
        ], [
            'name' => 'DirectAdmin password',
            'value' => $password,
        ]);

        return [
            'username' => $username,
            'password' => $password,
            'domain'   => $properties['domain'] ?? '',
            'ip'       => $ip,
        ];
    }

    /**
     * Suspend a server
     *
     * @param  array  $settings  (product settings)
     * @param  array  $properties  (checkout options)
     * @return bool
     */
    public function suspendServer(Service $service, $settings, $properties)
    {
        if (!isset($properties['directadmin_username'])) {
            throw new Exception('Service has not been created');
        }

        $response = $this->request('/CMD_API_SELECT_USERS', 'post', [
            'location' => 'CMD_SELECT_USERS',
            'suspend' => 'suspend',
            'select0' => $properties['directadmin_username'],
        ], parse: true);

        if ($response['error'] != '0') {
            throw new Exception('Error suspending DirectAdmin account: ' . $response['text']);
        }

        return true;
    }

    /**
     * Unsuspend a server
     *
     * @param  array  $settings  (product settings)
     * @param  array  $properties  (checkout options)
     * @return bool
     */
    public function unsuspendServer(Service $service, $settings, $properties)
    {
        if (!isset($properties['directadmin_username'])) {
            throw new Exception('Service has not been created');
        }

        $response = $this->request('/CMD_API_SELECT_USERS', 'post', [
            'location' => 'CMD_SELECT_USERS',
            'suspend' => 'unsuspend',
            'select0' => $properties['directadmin_username'],
        ], parse: true);

        if ($response['error'] != '0') {
            throw new Exception('Error unsuspending DirectAdmin account: ' . $response['text']);
        }

        return true;
    }

    /**
     * Terminate a server
     *
     * @param  array  $settings  (product settings)
     * @param  array  $properties  (checkout options)
     * @return bool
     */
    public function terminateServer(Service $service, $settings, $properties)
    {
        if (!isset($properties['directadmin_username'])) {
            throw new Exception('Service has not been created');
        }

        $response = $this->request('/CMD_API_SELECT_USERS', 'post', [
            'confirmed' => 'Confirm',
            'delete' => 'yes',
            'select0' => $properties['directadmin_username'],
        ], parse: true);

        if ($response['error'] != '0') {
            throw new Exception('Error terminating DirectAdmin account: ' . $response['text']);
        }

        // Delete the properties
        $service->properties()->where('key', 'directadmin_username')->delete();

        return true;
    }

    public function getActions(Service $service, $settings, $properties): array
    {
        if (!isset($properties['directadmin_username'], $properties['directadmin_password'])) {
            return [];
        }

        return [
            [
                'label' => 'Access DirectAdmin',
                'type' => 'button',
                'function' => 'ssoLink',
            ],
        ];
    }

    public function ssoLink(Service $service, $settings, $properties): string
    {
        if (!isset($properties['directadmin_username'], $properties['directadmin_password'])) {
            return '';
        }

        $response = Http::withBasicAuth($properties['directadmin_username'], $properties['directadmin_password'])
            ->post(rtrim($this->config('host'), '/') . '/CMD_API_LOGIN_KEYS', [
                'action' => 'create',
                'type' => 'one_time_url',
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

    /**
     * Upgrade / change plan for an existing account.
     *
     * If a "storage" configurable option is present the account is updated with
     * DirectAdmin's custom-limits mode; otherwise the call is a no-op (named
     * package upgrades are handled outside this extension).
     *
     * @param  array  $settings    (product settings)
     * @param  array  $properties  (configurable option values)
     * @return bool
     */
    public function upgrade(Service $service, $settings, $properties)
    {
        $username = $service->properties()->where('key', 'directadmin_username')->first()?->value;

        if (!$username) {
            throw new Exception('Service has not been created');
        }

        $storage   = $properties['storage'] ?? null;
        $bandwidth = $properties['bandwidth'] ?? null;

        if ($storage !== null) {
            $response = $this->request('/CMD_API_MODIFY_USER', 'post', array_merge([
                'action' => 'customize',
                'user'   => $username,
            ], $this->buildCustomLimitsPayload((int) $storage, $bandwidth)), parse: true);

            if ($response['error'] != '0') {
                throw new Exception('Error upgrading DirectAdmin account: ' . $response['text']);
            }
        }

        return true;
    }

    /**
     * Build the DirectAdmin custom-limits payload array shared by createServer() and upgrade().
     */
    private function buildCustomLimitsPayload(int $quota, $bandwidth): array
    {
        return [
            'custom'      => 'yes',
            'quota'       => $quota,
            'bandwidth'   => $bandwidth ?: 'unlimited',
            'vdomains'    => 'unlimited',
            'nsubdomains' => 'unlimited',
            'nemails'     => 'unlimited',
            'nemailf'     => 'unlimited',
            'nemailml'    => 'unlimited',
            'nemailr'     => 'unlimited',
            'mysql'       => 'unlimited',
            'domainptr'   => 'unlimited',
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
        ];
    }

    /**
     * Derive a unique DirectAdmin username from the customer's email address.
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

        // Fetch existing usernames for collision detection.
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
                // Last resort: 3 random lowercase alphanumeric chars.
                // Re-truncate base to 13 so that base(13) + suffix(3) = 16 chars max.
                $candidate = substr($base, 0, 13) . strtolower(Str::random(3));
            }
        }

        return $candidate;
    }
}
