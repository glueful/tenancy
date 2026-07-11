<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Resolution;

/** Injectable DNS TXT lookup used by domain verification. */
class DnsTxtLookup
{
    public function lookupStructured(string $name): DnsTxtResult
    {
        $records = dns_get_record($name, DNS_TXT);
        if ($records === false) {
            return new DnsTxtResult('error');
        }

        $values = [];
        foreach ($records as $record) {
            $value = $record['txt'] ?? null;
            if (is_string($value)) {
                $values[] = $value;
            }
        }

        return new DnsTxtResult('success', $values);
    }

    /** @return list<string> */
    public function lookup(string $name): array
    {
        return $this->lookupStructured($name)->records;
    }
}
