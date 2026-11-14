---
paths:
  - 'resources/js/**'
---

# Js

## Regenerate Wayfinder with --with-form
The Vite plugin runs Wayfinder with formVariants: true, and the pages use Controller.action.form(...) everywhere. A plain `php artisan wayfinder:generate` rewrites resources/js/actions and routes without the form variants, and tsc then reports hundreds of missing `.form` properties that look like a framework or Wayfinder break. Use `php artisan wayfinder:generate --with-form` (or `npm run build`) when regenerating by hand. Found 2026-11-14 while lifting the framework cap.
