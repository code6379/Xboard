<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

abstract class SubscriptionListCommand extends Command
{
    protected string $environmentKey;

    protected string $entryName;

    public function handle(): int
    {
        $action = strtolower((string) $this->argument('action'));
        if (!in_array($action, ['add', 'remove', 'list'], true)) {
            $this->error('操作仅支持 add、remove 或 list。');
            return self::INVALID;
        }

        $path = $this->getListPath();
        if ($path === null) {
            $this->error(".env 中未配置 {$this->environmentKey}。");
            return self::FAILURE;
        }

        if ($action === 'list') {
            return $this->listValues($path);
        }

        $value = $this->normalizeValue((string) $this->argument('value'));
        if ($value === null) {
            $this->error("请输入有效的{$this->entryName}。\n");
            return self::INVALID;
        }

        return $action === 'add'
            ? $this->addValue($path, $value)
            : $this->removeValue($path, $value);
    }

    abstract protected function normalizeValue(string $value): ?string;

    private function listValues(string $path): int
    {
        if (!is_file($path)) {
            $this->info("{$this->entryName}名单为空。");
            return self::SUCCESS;
        }

        if (!is_readable($path)) {
            $this->error("无法读取名单文件：{$path}");
            return self::FAILURE;
        }

        $values = $this->getEntries((string) file_get_contents($path));
        if ($values === []) {
            $this->info("{$this->entryName}名单为空。");
            return self::SUCCESS;
        }

        $this->table([$this->entryName], array_map(fn(string $value): array => [$value], $values));
        return self::SUCCESS;
    }

    private function addValue(string $path, string $value): int
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            $this->error("无法创建名单目录：{$directory}");
            return self::FAILURE;
        }

        $file = fopen($path, 'c+');
        if ($file === false) {
            $this->error("无法写入名单文件：{$path}");
            return self::FAILURE;
        }

        try {
            if (!flock($file, LOCK_EX)) {
                $this->error("无法锁定名单文件：{$path}");
                return self::FAILURE;
            }

            $content = stream_get_contents($file) ?: '';
            foreach ($this->getEntries($content) as $entry) {
                if ($this->valuesMatch($entry, $value)) {
                    $this->warn("{$this->entryName}已存在：{$value}");
                    return self::SUCCESS;
                }
            }

            fseek($file, 0, SEEK_END);
            fwrite($file, (strlen($content) > 0 && !str_ends_with($content, "\n") ? "\n" : '') . $value . "\n");
            $this->info("已添加{$this->entryName}：{$value}");
            return self::SUCCESS;
        } finally {
            flock($file, LOCK_UN);
            fclose($file);
        }
    }

    private function removeValue(string $path, string $value): int
    {
        if (!is_file($path)) {
            $this->warn("{$this->entryName}不存在：{$value}");
            return self::SUCCESS;
        }

        $file = fopen($path, 'c+');
        if ($file === false) {
            $this->error("无法写入名单文件：{$path}");
            return self::FAILURE;
        }

        try {
            if (!flock($file, LOCK_EX)) {
                $this->error("无法锁定名单文件：{$path}");
                return self::FAILURE;
            }

            $content = stream_get_contents($file) ?: '';
            $lines = preg_split('/\R/', $content) ?: [];
            $removed = false;
            $lines = array_values(array_filter($lines, function (string $line) use ($value, &$removed): bool {
                if ($this->valuesMatch(trim($line), $value)) {
                    $removed = true;
                    return false;
                }

                return true;
            }));

            if (!$removed) {
                $this->warn("{$this->entryName}不存在：{$value}");
                return self::SUCCESS;
            }

            $newContent = implode("\n", $lines);
            ftruncate($file, 0);
            rewind($file);
            fwrite($file, $newContent);
            $this->info("已移除{$this->entryName}：{$value}");
            return self::SUCCESS;
        } finally {
            flock($file, LOCK_UN);
            fclose($file);
        }
    }

    private function getListPath(): ?string
    {
        $path = trim((string) env($this->environmentKey));
        return $path === '' ? null : base_path($path);
    }

    /**
     * @return array<int, string>
     */
    private function getEntries(string $content): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', $content) ?: []), fn(string $line): bool => $line !== '' && !str_starts_with($line, '#')));
    }

    private function valuesMatch(string $entry, string $value): bool
    {
        return strtolower($entry) === strtolower($value);
    }
}
