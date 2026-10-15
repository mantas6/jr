---
paths:
  - 'app/Filament/**'
---

# Filament

## Always prefer the native Filament approach
When implementing UI or panel features, always use Filament's built-in components and APIs first (e.g. RichEditor mentions, column/record actions, relation managers, tabs, infolist entries). Check vendor/filament source or the docs for a native option before writing custom Blade, JS, Livewire or bespoke logic. Only build custom code when Filament has no native support, and say so explicitly.
