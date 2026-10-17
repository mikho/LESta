<?php

namespace Tests;

use Symfony\Component\Yaml\Yaml;

/**
 * Renders docs/api/openapi.yaml as resources/docs/api-reference.md, the panel's "API Reference"
 * guide: one chapter per area, every operation with its parameters, body and answers, and the
 * schemas. Run by tests/Feature/Api/ApiReferenceTest.php, which fails when the committed page is
 * out of date and rewrites it with UPDATE_API_REFERENCE=1. It lives with the tests because the
 * YAML reader is a development dependency only.
 */
class ApiReferenceGenerator
{
    /** Chapter title for each group of paths, in the order they appear. */
    private const array GROUPS = [
        'basics' => 'Your token, accounts and operations',
        'web-domains' => 'Web domains',
        'dns-zones' => 'DNS',
        'mail-domains' => 'Mail',
        'databases' => 'Databases',
        'cron-jobs' => 'Cron jobs',
        'backups' => 'Backups',
        'usage' => 'Usage',
        'admin-accounts' => 'Administration: accounts',
        'admin-packages' => 'Administration: packages',
        'admin-nodes' => 'Administration: nodes',
        'admin-node-backups' => 'Administration: whole-node backups',
        'admin-lists' => 'Administration: every customer\'s resources',
        'schemas' => 'Schemas',
    ];

    private const string SCHEMAS_PAGE = '@schemas-page@';

    /** @var array<string, mixed> */
    private array $spec;

    public function __construct(?string $file = null)
    {
        $this->spec = Yaml::parseFile($file ?? dirname(__DIR__).'/docs/api/openapi.yaml');
    }

    public static function target(): string
    {
        return dirname(__DIR__).'/resources/docs/api-reference.md';
    }

    public function markdown(): string
    {
        $chapters = array_fill_keys(array_keys(self::GROUPS), '');

        foreach ($this->spec['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                $chapters[$this->group($path)] .= $this->operation($path, $method, $operation);
            }
        }

        $chapters['schemas'] = $this->schemas();

        $out = "# LESta API Reference\n\n"
            ."Every call of the LESta API, generated from the OpenAPI description (`docs/api/openapi.yaml`, version {$this->spec['info']['version']}). "
            ."Paths are under `/api/v1` on your panel's address. The API Guide explains tokens, errors, paging, operations and retries; this page lists what each call takes and answers.\n";

        $number = 0;

        foreach (self::GROUPS as $key => $title) {
            if (trim($chapters[$key]) === '') {
                continue;
            }

            $number++;
            $out .= "\n---\n\n## Chapter {$number}: {$title}\n".$chapters[$key];
        }

        // The docs show one chapter per page, so a schema link names the Schemas chapter's page.
        return str_replace(self::SCHEMAS_PAGE, '/docs/api-reference/chapter-'.$number, $out);
    }

    private function group(string $path): string
    {
        if (preg_match('#^/admin/(accounts|packages|nodes|node-backups)\b#', $path, $match)) {
            return 'admin-'.$match[1];
        }

        if (str_starts_with($path, '/admin/')) {
            return 'admin-lists';
        }

        if (preg_match('#^/accounts/\{account\}/([a-z-]+)#', $path, $match) && array_key_exists($match[1], self::GROUPS)) {
            return $match[1];
        }

        return 'basics';
    }

    /**
     * @param  array<string, mixed>  $operation
     */
    private function operation(string $path, string $method, array $operation): string
    {
        $out = "\n### `".strtoupper($method).' '.$path."`\n\n".$this->sentence($operation['summary'] ?? '')."\n";

        if (isset($operation['description'])) {
            $out .= "\n".trim($operation['description'])."\n";
        }

        $parameters = array_map(fn (array $parameter): array => $this->resolve($parameter), $operation['parameters'] ?? []);

        if ($parameters !== []) {
            $out .= "\n| Parameter | In | Required | Description |\n| --- | --- | --- | --- |\n";

            foreach ($parameters as $parameter) {
                $out .= '| `'.$parameter['name'].'` | '.$parameter['in'].' | '.(($parameter['required'] ?? false) ? 'yes' : 'no').' | '.$this->cell(trim($parameter['description'] ?? $this->type($parameter['schema'] ?? []))).' |'."\n";
            }
        }

        if (isset($operation['requestBody'])) {
            $schema = $operation['requestBody']['content']['application/json']['schema'];
            $out .= "\n**Body:** ".$this->schemaLink($schema)."\n";
        }

        $out .= "\n**Answers:**\n\n";

        $responses = $operation['responses'];
        ksort($responses);

        foreach ($responses as $status => $response) {
            $resolved = $this->resolve($response);
            $schema = $resolved['content']['application/json']['schema'] ?? null;
            $data = $schema['properties']['data'] ?? null;
            $out .= "- `{$status}` ".$this->cell($this->sentence($resolved['description'] ?? ''));

            if ($data !== null) {
                $out .= ' Data: '.$this->schemaLink(isset($data['items']) ? $data['items'] : $data).(isset($data['items']) ? ' (a page of them)' : '').'.';
            } elseif (isset($resolved['content']['application/problem+json'])) {
                $out .= ' A [problem document]('.self::SCHEMAS_PAGE.'#problem).';
            }

            $out .= "\n";
        }

        return $out;
    }

    private function schemas(): string
    {
        $out = "\nThe objects the calls take and answer. A type with `null` may be null. `uuid` and `date-time` values are strings in those formats (timestamps in UTC).\n";

        foreach ($this->spec['components']['schemas'] as $name => $schema) {
            $out .= "\n### <a id=\"".strtolower($name).'"></a>'.$name."\n";

            if (isset($schema['description'])) {
                $out .= "\n".$this->sentence(trim($schema['description']))."\n";
            }

            [$properties, $required, $includes] = $this->flatten($schema);

            if ($includes !== []) {
                $out .= "\nAll the fields of ".implode(' and ', array_map(fn (string $include): string => $this->schemaLink(['$ref' => '#/components/schemas/'.$include]), $includes)).", and:\n";
            }

            if ($properties !== []) {
                $out .= "\n| Field | Type | Required | Description |\n| --- | --- | --- | --- |\n";

                foreach ($properties as $field => $property) {
                    $out .= '| `'.$field.'` | '.$this->cell($this->type($property)).' | '.(in_array($field, $required, true) ? 'yes' : 'no').' | '.$this->cell(trim($property['description'] ?? '')).' |'."\n";
                }
            } elseif (isset($schema['oneOf'])) {
                $out .= "\nOne of: ".implode(', ', array_map(fn (array $one): string => $this->type($one), $schema['oneOf'])).".\n";
            }
        }

        return $out;
    }

    /**
     * The schema's own fields, its required list and the schemas it includes through allOf.
     *
     * @param  array<string, mixed>  $schema
     * @return array{0: array<string, array<string, mixed>>, 1: list<string>, 2: list<string>}
     */
    private function flatten(array $schema): array
    {
        $properties = $schema['properties'] ?? [];
        $required = $schema['required'] ?? [];
        $includes = [];

        foreach ($schema['allOf'] ?? [] as $part) {
            if (isset($part['$ref'])) {
                $includes[] = substr($part['$ref'], strrpos($part['$ref'], '/') + 1);

                continue;
            }

            $properties += $part['properties'] ?? [];
            $required = [...$required, ...($part['required'] ?? [])];
        }

        return [$properties, $required, $includes];
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function type(array $schema): string
    {
        if (isset($schema['$ref'])) {
            return $this->schemaLink($schema);
        }

        if (isset($schema['oneOf'])) {
            return implode(' or ', array_map(fn (array $one): string => $this->type($one), $schema['oneOf']));
        }

        if (isset($schema['allOf'])) {
            return implode(' and ', array_map(fn (array $one): string => $this->type($one), $schema['allOf']));
        }

        $types = (array) ($schema['type'] ?? 'any');

        if (in_array('array', $types, true) && isset($schema['items'])) {
            $types = array_map(fn (string $type): string => $type === 'array' ? 'list of '.$this->type($schema['items']) : $type, $types);
        }

        $type = implode(' or ', $types);

        if (isset($schema['format'])) {
            $type .= ' ('.$schema['format'].')';
        }

        if (isset($schema['enum'])) {
            $type .= ': '.implode(', ', array_map(fn (mixed $value): string => $value === null ? 'null' : '`'.$value.'`', $schema['enum']));
        }

        if (isset($schema['pattern'])) {
            $type .= ', matching `'.$schema['pattern'].'`';
        }

        foreach (['minimum' => 'at least', 'maximum' => 'at most', 'minItems' => 'at least', 'maxLength' => 'at most'] as $key => $words) {
            if (isset($schema[$key])) {
                $type .= ', '.$words.' '.$schema[$key].($key === 'maxLength' ? ' characters' : ($key === 'minItems' ? ' item' : ''));
            }
        }

        if (isset($schema['const'])) {
            $type .= ': `'.json_encode($schema['const']).'`';
        }

        return $type;
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function schemaLink(array $schema): string
    {
        if (! isset($schema['$ref'])) {
            return $this->type($schema);
        }

        $name = substr($schema['$ref'], strrpos($schema['$ref'], '/') + 1);

        return '['.$name.']('.self::SCHEMAS_PAGE.'#'.strtolower($name).')';
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function resolve(array $item): array
    {
        if (! isset($item['$ref'])) {
            return $item;
        }

        $node = $this->spec;

        foreach (explode('/', substr($item['$ref'], 2)) as $part) {
            $node = $node[$part];
        }

        return $node;
    }

    private function cell(string $text): string
    {
        return str_replace(['|', "\n"], ['\\|', ' '], $text);
    }

    private function sentence(string $text): string
    {
        $text = trim($text);

        return $text === '' || str_ends_with($text, '.') ? $text : $text.'.';
    }
}
