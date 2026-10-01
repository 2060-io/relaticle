<script>
    window.resizableWidths = {
        'sidebar-width': { property: '--sidebar-width', min: 220, max: 360 },
        'record-rail-width': { property: '--record-rail-width', min: 320, max: 560 },
        'chat-panel-width': { property: '--chat-panel-width', min: 360, max: 720 },
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

    document.addEventListener('alpine:init', () => {
        Alpine.data('recordLayout', () => ({
            observer: null,

            init() {
                const railMin = window.resizableWidths['record-rail-width'].min
                const paneMin = parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--record-pane-min-width'))

                this.observer = new ResizeObserver(([entry]) => {
                    document.documentElement.classList.toggle('fi-record-stacked', entry.contentRect.width < railMin + paneMin)
                })

                this.observer.observe(this.$el.closest('.fi-main-ctn'))
            },

            destroy() {
                this.observer.disconnect()
                document.documentElement.classList.remove('fi-record-stacked')
            },
        }))
    })
</script>
