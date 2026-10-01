<script>
    window.resizableWidths = {
        'sidebar-width': { property: '--sidebar-width', min: 220, max: 360 },
        'record-rail-width': { property: '--record-rail-width', min: 320, max: 560 },
    }

    const loadResizableWidths = () => {
        Object.entries(window.resizableWidths).forEach(([storageKey, { property, min, max }]) => {
            const width = Number(localStorage.getItem(storageKey))

            if (width >= min && width <= max) {
                document.documentElement.style.setProperty(property, `${width}px`)
            }
        })
    }

    loadResizableWidths()

    document.addEventListener('livewire:navigated', loadResizableWidths)
</script>
