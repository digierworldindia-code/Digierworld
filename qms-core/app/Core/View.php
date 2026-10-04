<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Plain-PHP templates from app/Views with layouts:
 *
 *   <?= $this->extend('layouts/app') ?>
 *   <?= $this->section('content') ?> … <?= $this->endSection() ?>
 *   layout: <?= $this->renderSection('content') ?>
 *   partial: <?= $this->include('inspections/_param') ?> or view('…', [...])
 *
 * Data passed to view() stays available to the partials rendered after it in
 * the same request. Templates escape output with esc().
 */
final class View
{
    /** @var array<string, mixed> */
    private array $data = [];

    /** @var array<string, mixed>|null */
    private ?array $tempData = null;

    private ?string $layout = null;

    /** @var array<string, list<string>> */
    private array $sections = [];

    /** @var list<string> */
    private array $sectionStack = [];

    /** @var list<string> */
    private array $fileStack = [];

    public function __construct(private readonly string $directory)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function setData(array $data): self
    {
        $this->tempData ??= $this->data;
        $this->tempData = array_merge($this->tempData, $data);

        return $this;
    }

    public function render(string $view, bool $saveData = true): string
    {
        $file = $this->resolve($view);

        $this->tempData ??= $this->data;
        $data = $this->tempData;
        if ($saveData) {
            $this->data = $data;
        }

        $this->fileStack[] = $file;
        $level             = ob_get_level();
        ob_start();

        try {
            (function (string $__file, array $__data): void {
                extract($__data, EXTR_SKIP);
                include $__file;
            })->call($this, $file, $data);
            $output = (string) ob_get_clean();
        } catch (\Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            $this->sectionStack = [];

            throw $e;
        } finally {
            array_pop($this->fileStack);
        }

        if ($this->layout !== null && $this->sectionStack === []) {
            $layout       = $this->layout;
            $this->layout = null;
            $output       = $this->render($layout, $saveData);
        }

        $this->tempData = null;

        return $output;
    }

    public function extend(string $layout): void
    {
        $this->layout = $layout;
    }

    public function section(string $name): void
    {
        $this->sectionStack[] = $name;
        ob_start();
    }

    public function endSection(): void
    {
        $contents = (string) ob_get_clean();
        $name     = array_pop($this->sectionStack);
        if ($name === null) {
            throw new \LogicException('endSection() without section().');
        }
        $this->sections[$name][] = $contents;
    }

    /** Prints a section collected from the child view. */
    public function renderSection(string $name, bool $saveData = false): void
    {
        foreach ($this->sections[$name] ?? [] as $key => $contents) {
            echo $contents;
            if (! $saveData) {
                unset($this->sections[$name][$key]);
            }
        }
    }

    public function include(string $view, ?array $options = null, bool $saveData = true): string
    {
        return $this->render($view, $saveData);
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->tempData ?? $this->data;
    }

    private function resolve(string $view): string
    {
        $view = trim(str_replace('\\', '/', $view), '/');
        if (str_ends_with($view, '.php')) {
            $view = substr($view, 0, -4);
        }
        if (! preg_match('#^[A-Za-z0-9_\-]+(/[A-Za-z0-9_\-]+)*$#', $view)) {
            throw new \InvalidArgumentException("Invalid view name: {$view}");
        }
        $file = $this->directory . DIRECTORY_SEPARATOR . $view . '.php';
        if (! is_file($file)) {
            throw new \RuntimeException("View not found: {$view}");
        }

        return $file;
    }
}
