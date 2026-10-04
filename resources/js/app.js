import Alpine from "alpinejs"
import collapse from "@alpinejs/collapse"

// Blade reads these through Vite::asset(). Eager, because the build drops a lazy glob nothing imports.
import.meta.glob(["../images/**"], { eager: true, query: "?url", import: "default" })

Alpine.plugin(collapse)
window.Alpine = Alpine
Alpine.start()
