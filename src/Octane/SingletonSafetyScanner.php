<?php

namespace Cyberwizard\WatchtowerAgent\Octane;

use ReflectionClass;
use ReflectionNamedType;
use Throwable;

final class SingletonSafetyScanner
{
    public function scan(): array
    {
        $bindings = app()->getBindings();
        $classes = 0;
        $findings = [];

        foreach ($bindings as $abstract => $binding) {
            $concrete = $binding['concrete'] ?? null;

            if (! ($binding['shared'] ?? false)) {
                continue;
            }

            $class = is_string($concrete)
                ? $concrete
                : (is_string($abstract) ? $abstract : null);

            if (! is_string($class) || ! str_starts_with($class, 'App\\') || ! class_exists($class)) {
                continue;
            }

            try {
                $reflection = new ReflectionClass($class);
                $file = $reflection->getFileName();

                if (! is_string($file) || ! str_starts_with($file, app_path().DIRECTORY_SEPARATOR)) {
                    continue;
                }

                $source = file_get_contents($file);

                if (! is_string($source)) {
                    continue;
                }

                $classes++;

                foreach ($reflection->getProperties() as $property) {
                    if ($property->getDeclaringClass()->getName() !== $class || $property->isStatic() || $property->isReadOnly()) {
                        continue;
                    }

                    $name = $property->getName();
                    $type = $property->getType();
                    $typeName = $type instanceof ReflectionNamedType ? $type->getName() : '';
                    $contextualName = preg_match('/request|session|user|tenant|auth|current/i', $name) === 1;
                    $contextualType = preg_match('/Request|Session|Authenticatable|\\\\User$|Tenant/i', $typeName) === 1;
                    $assignment = preg_match('/\$this->'.preg_quote($name, '/').'\s*=\s*(?:request\s*\(|auth\s*\(|session\s*\(|app\s*\(\s*[\'\"](?:request|session)|(?:Auth|Request)::(?:user|guard|capture)|\$[A-Za-z_]*(?:request|session|user|tenant|context)[A-Za-z_]*\b)/i', $source) === 1;

                    if (! $contextualName && ! $contextualType && ! $assignment) {
                        continue;
                    }

                    $line = $this->propertyLine($source, $name);
                    $rule = $assignment ? 'request_context_retained' : 'request_context_property';

                    $findings[] = [
                        'class' => $class,
                        'property' => $name,
                        'rule' => $rule,
                        'severity' => 'high',
                        'confidence' => $assignment || $contextualType ? 'high' : 'medium',
                        'file' => str_replace(base_path().DIRECTORY_SEPARATOR, '', $file),
                        'line' => $line,
                        'message' => 'A singleton has mutable state that may retain request-specific data between Octane requests.',
                        'recommendation' => 'Keep request data in method scope, use scoped bindings for request-specific services, or reset the property at the end of each request.',
                    ];
                }
            } catch (Throwable) {
                continue;
            }
        }

        return [
            'scanner_version' => '1.0.0',
            'classes_scanned' => $classes,
            'findings_count' => count($findings),
            'findings' => array_slice($findings, 0, 200),
            'timestamp' => microtime(true),
        ];
    }

    private function propertyLine(string $source, string $property): ?int
    {
        if (preg_match('/^\s*(?:public|protected|private)\s+(?:(?:readonly|static)\s+)*(?:\??[\\\\\w|&]+\s+)?\$'.preg_quote($property, '/').'\b/m', $source, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        return substr_count(substr($source, 0, $match[0][1]), "\n") + 1;
    }
}
