<?php
namespace Translink\Utils;

class Validator
{
    private array $errors = [];

    public function validate(array $data, array $rules): bool
    {
        $this->errors = [];

        foreach ($rules as $field => $ruleSet) {
            $rules = is_array($ruleSet) ? $ruleSet : explode('|', $ruleSet);
            $value = $data[$field] ?? null;

            foreach ($rules as $rule) {
                $params = [];
                if (str_contains($rule, ':')) {
                    [$rule, $paramStr] = explode(':', $rule, 2);
                    $params = explode(',', $paramStr);
                }

                $methodName = 'rule' . ucfirst($rule);
                if (method_exists($this, $methodName)) {
                    $this->$methodName($field, $value, $params, $data);
                }
            }
        }

        return empty($this->errors);
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(): ?string
    {
        $all = $this->errors();
        return $all ? $all[0] : null;
    }

    private function addError(string $field, string $message): void
    {
        $this->errors[] = "{$field}: {$message}";
    }

    private function ruleRequired(string $field, mixed $value, array $params, array $data): void
    {
        if ($value === null || $value === '') {
            $this->addError($field, 'This field is required');
        }
    }

    private function ruleEmail(string $field, mixed $value, array $params, array $data): void
    {
        if ($value !== null && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->addError($field, 'Must be a valid email address');
        }
    }

    private function ruleMin(string $field, mixed $value, array $params, array $data): void
    {
        $min = (int)($params[0] ?? 0);
        if (is_string($value) && strlen($value) < $min) {
            $this->addError($field, "Must be at least {$min} characters");
        }
        if (is_numeric($value) && $value < $min) {
            $this->addError($field, "Must be at least {$min}");
        }
    }

    private function ruleMax(string $field, mixed $value, array $params, array $data): void
    {
        $max = (int)($params[0] ?? 0);
        if (is_string($value) && strlen($value) > $max) {
            $this->addError($field, "Must not exceed {$max} characters");
        }
        if (is_numeric($value) && $value > $max) {
            $this->addError($field, "Must not exceed {$max}");
        }
    }

    private function ruleIn(string $field, mixed $value, array $params, array $data): void
    {
        if ($value !== null && $value !== '' && !in_array((string)$value, $params)) {
            $this->addError($field, "Must be one of: " . implode(', ', $params));
        }
    }

    private function ruleInteger(string $field, mixed $value, array $params, array $data): void
    {
        if ($value !== null && $value !== '' && !filter_var($value, FILTER_VALIDATE_INT)) {
            $this->addError($field, 'Must be an integer');
        }
    }

    private function ruleNumeric(string $field, mixed $value, array $params, array $data): void
    {
        if ($value !== null && $value !== '' && !is_numeric($value)) {
            $this->addError($field, 'Must be numeric');
        }
    }

    private function ruleSlug(string $field, mixed $value, array $params, array $data): void
    {
        if ($value !== null && $value !== '' && !preg_match('/^[a-z0-9-]+$/', $value)) {
            $this->addError($field, 'Must be a valid slug (lowercase, numbers, hyphens)');
        }
    }

    private function ruleFile(string $field, mixed $value, array $params, array $data): void
    {
        if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
            if (($data[$field] ?? null) === null) {
                $this->addError($field, 'File upload is required');
            }
        }
    }

    private function ruleExtensions(string $field, mixed $value, array $params, array $data): void
    {
        if (isset($_FILES[$field]) && $_FILES[$field]['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $params)) {
                $this->addError($field, "File type .{$ext} is not allowed. Allowed: " . implode(', ', $params));
            }
        }
    }
}
