<?php

namespace Paymenter\Extensions\Servers\DomainResellerApi\Livewire;

use App\Livewire\Component;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Paymenter\Extensions\Servers\DomainResellerApi\DomainResellerApi;
use Paymenter\Extensions\Servers\DomainResellerApi\Livewire\Concerns\ManagesDomain;
use Paymenter\Extensions\Servers\DomainResellerApi\Support\ApiClient;
use Paymenter\Extensions\Servers\DomainResellerApi\Support\ApiException;

class DnsRecords extends Component
{
    use ManagesDomain;

    /** Record types customers may manage. */
    public const TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'CAA', 'NS', 'TLSA', 'HTTPS', 'SVCB', 'Redirect'];

    /** Types that use the priority field. */
    public const PRIORITY_TYPES = ['MX', 'SRV'];

    #[Locked]
    public array $records = [];

    /** Managed DNS is active for the domain (records can only be managed then). */
    #[Locked]
    public bool $managed = true;

    /** Id of the record being edited, null when creating a new one. */
    #[Locked]
    public ?string $editing = null;

    public string $type = 'A';

    public string $name = '@';

    public string $content = '';

    public ?int $ttl = 3600;

    public ?int $priority = null;

    /** BIND zone file to import. */
    public string $zoneFile = '';

    protected function feature(): string
    {
        return 'dns';
    }

    protected function load(): void
    {
        try {
            $data = $this->client()->getDnsRecords($this->domain())['data'] ?? [];
        } catch (ApiException $e) {
            if (str_contains(strtolower($e->getMessage()), 'managed dns is not enabled')) {
                $this->managed = false;
                $this->records = [];
                $this->loadError = null;

                return;
            }
            $this->loadError = $e->getMessage();

            return;
        }

        $this->loadError = null;
        $this->managed = (bool) ($data['useManagedDns'] ?? true);
        $records = array_values(array_filter($data['records'] ?? [], fn ($r) => is_array($r) && isset($r['id'])));
        usort($records, fn ($a, $b) => [$a['type'], $a['name']] <=> [$b['type'], $b['name']]);
        $this->records = $records;
    }

    protected function rules(): array
    {
        return [
            'type' => ['required', Rule::in(self::TYPES)],
            'name' => ['required', 'string', 'max:253', 'regex:/^(@|\*|(\*\.)?[A-Za-z0-9_]([A-Za-z0-9_-]{0,62})(\.[A-Za-z0-9_]([A-Za-z0-9_-]{0,62}))*)$/'],
            'content' => ['required', 'string', 'max:4096'],
            'ttl' => ['nullable', 'integer', 'min:60', 'max:86400'],
            'priority' => [Rule::requiredIf(in_array($this->type, self::PRIORITY_TYPES, true)), 'nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }

    public function create(): void
    {
        $this->resetForm();
    }

    public function edit(string $id): void
    {
        $record = $this->findRecord($id);
        if ($record === null) {
            return;
        }

        $this->editing = $record['id'];
        $this->type = (string) $record['type'];
        $this->name = (string) $record['name'] === '' ? '@' : (string) $record['name'];
        $this->content = (string) $record['content'];
        $this->ttl = isset($record['ttl']) ? (int) $record['ttl'] : null;
        $this->priority = isset($record['priority']) ? (int) $record['priority'] : null;
    }

    public function save(): void
    {
        $this->validate();

        $priority = in_array($this->type, self::PRIORITY_TYPES, true) ? $this->priority : null;
        $name = trim($this->name);
        $content = trim($this->content);

        if ($this->editing !== null) {
            if ($this->findRecord($this->editing) === null) {
                return;
            }
            $data = array_filter([
                'name' => $name,
                'content' => $content,
                'ttl' => $this->ttl,
                'priority' => $priority,
            ], fn ($v) => $v !== null);

            $result = $this->attempt(
                fn (ApiClient $client, string $domain) => $client->updateDnsRecord($domain, $this->editing, $data),
                $this->t('dns.updated'),
            );
        } else {
            $result = $this->attempt(
                fn (ApiClient $client, string $domain) => $client->createDnsRecord($domain, $this->type, $name, $content, $this->ttl, $priority),
                $this->t('dns.created'),
            );
        }

        if ($result !== null) {
            $this->resetForm();
            $this->load();
        }
    }

    public function delete(string $id): void
    {
        if ($this->findRecord($id) === null) {
            return;
        }

        $result = $this->attempt(
            fn (ApiClient $client, string $domain) => $client->deleteDnsRecord($domain, $id),
            $this->t('dns.deleted'),
        );

        if ($result !== null) {
            if ($this->editing === $id) {
                $this->resetForm();
            }
            $this->load();
        }
    }

    public function enableManagedDns(): void
    {
        $result = $this->attempt(
            fn (ApiClient $client, string $domain) => $client->enableManagedDns($domain),
            $this->t('dns.managed_enabled'),
        );

        if ($result !== null) {
            $this->load();
        }
    }

    /**
     * Download the zone as BIND file.
     */
    public function exportZone()
    {
        $result = $this->attempt(fn (ApiClient $client, string $domain) => $client->exportZone($domain));
        $zone = is_array($result) ? ($result['data'] ?? null) : null;
        if (!is_string($zone)) {
            return null;
        }

        $filename = $this->domain() . '.zone';

        return response()->streamDownload(function () use ($zone) {
            echo $zone;
        }, $filename, ['Content-Type' => 'text/plain']);
    }

    public function importZone(): void
    {
        $this->validate(['zoneFile' => ['required', 'string', 'max:1000000']]);

        $result = $this->attempt(
            fn (ApiClient $client, string $domain) => $client->importZone($domain, $this->zoneFile),
            $this->t('dns.imported'),
        );

        if ($result !== null) {
            $this->zoneFile = '';
            $this->load();
        }
    }

    public function resetForm(): void
    {
        $this->resetErrorBag();
        $this->editing = null;
        $this->type = 'A';
        $this->name = '@';
        $this->content = '';
        $this->ttl = 3600;
        $this->priority = null;
    }

    /**
     * Only records that belong to the loaded zone can be edited or deleted.
     */
    private function findRecord(string $id): ?array
    {
        foreach ($this->records as $record) {
            if ((string) $record['id'] === $id) {
                return $record;
            }
        }

        return null;
    }

    public function render()
    {
        return view(DomainResellerApi::NAME . '::livewire.dns', [
            'types' => self::TYPES,
            'priorityTypes' => self::PRIORITY_TYPES,
        ]);
    }
}
