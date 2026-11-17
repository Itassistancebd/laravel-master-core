# Master Core UI

This private npm workspace package contains the shared Vue 3 + TypeScript form primitives used by Core modules. It is intentionally limited to reusable controls and styles; feature-specific pages remain in their owning packages.

The current primitives include labeled fields, text/color/password inputs, checkboxes, buttons, searchable single and multiple selects, confirmation dialogs, toast feedback, and shared form tokens. They provide keyboard support and accessible names, descriptions, and validation states. New shared controls should follow the Global UI and CRUD Standards and should be added only when a real module needs them.

The package is consumed through the root npm workspace as `@itassistance-bd/ui`. Run `npm run typecheck`, `npm run test:ui`, and `npm run build` from the repository root to verify it with its consumer.


## External npm installation

After this package and its dependencies have been published to the verified public npm scope, install it in a consumer project with:

```sh
npm install @itassistance-bd/ui
```

The package is not asserted to be published by this source repository. Until the publisher confirms registry availability and releases a compatible version, use the repository workspace setup described in the root README.
