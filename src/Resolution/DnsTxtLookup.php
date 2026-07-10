<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Resolution;

/** Injectable DNS TXT lookup used by domain verification. */
class DnsTxtLookup
{
    /** @return list<string> */
    public function lookup(string $name): array
    {
        $records = dns_get_record($name, DNS_TXT);
        if (!is_array($records)) {
            return [];
        }

        $values = [];
        foreach ($records as $record) {
            $value = $record['txt'] ?? null;
            if (is_string($value)) {
                $values[] = $value;
            }
        }

        return $values;
    }
}
