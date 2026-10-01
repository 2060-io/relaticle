@props([
    'storageKey',
    'target',
    'label',
    'side' => 'end',
])

<div
    x-data="{
        storageKey: @js($storageKey),
        width: null,
        isResizing: false,
        get growsTowardLeft() {
            return (@js($side) === 'start') !== (document.dir === 'rtl')
        },
        get bounds() {
            return window.resizableWidths[this.storageKey]
        },
        get target() {
            return document.querySelector(@js($target))
        },
        get max() {
            const reserve = parseFloat(getComputedStyle(this.target).getPropertyValue('--resize-reserve')) || 0

            return reserve > 0 ? Math.min(this.bounds.max, this.target.parentElement.clientWidth - reserve) : this.bounds.max
        },
        clamp(width) {
            return Math.round(Math.min(this.max, Math.max(this.bounds.min, width)))
        },
        apply(width) {
            this.width = this.clamp(width)
            document.documentElement.style.setProperty(this.bounds.property, `${this.width}px`)
        },
        save() {
            localStorage.setItem(this.storageKey, this.width)
        },
        start(event) {
            if (event.button !== 0) return

            const edge = this.target.getBoundingClientRect()
            const growsTowardLeft = this.growsTowardLeft

            event.target.setPointerCapture(event.pointerId)
            this.isResizing = true
            document.documentElement.classList.add('fi-resizing')

            const move = (moveEvent) => this.apply(growsTowardLeft ? edge.right - moveEvent.clientX : moveEvent.clientX - edge.left)
            const stop = () => {
                event.target.removeEventListener('pointermove', move)
                this.isResizing = false
                document.documentElement.classList.remove('fi-resizing')
                this.save()
            }

            event.target.addEventListener('pointermove', move)
            event.target.addEventListener('pointerup', stop, { once: true })
            event.target.addEventListener('pointercancel', stop, { once: true })
        },
        nudge(delta) {
            this.apply(this.clamp(this.width ?? this.target.offsetWidth) + (this.growsTowardLeft ? -delta : delta))
            this.save()
        },
        jump(width) {
            this.apply(width)
            this.save()
        },
        reset() {
            document.documentElement.style.removeProperty(this.bounds.property)
            localStorage.removeItem(this.storageKey)
            this.width = null
        },
    }"
    x-init="width = Number(localStorage.getItem(storageKey)) || null"
    x-on:pointerdown="start($event)"
    x-on:dblclick="reset()"
    x-on:keydown.arrow-left.prevent="nudge(-16)"
    x-on:keydown.arrow-right.prevent="nudge(16)"
    x-on:keydown.home.prevent="jump(bounds.min)"
    x-on:keydown.end.prevent="jump(max)"
    x-bind:class="{ 'fi-resize-handle-active': isResizing }"
    role="separator"
    aria-orientation="vertical"
    aria-label="{{ $label }}"
    x-bind:aria-valuenow="clamp(width ?? target.offsetWidth)"
    x-bind:aria-valuemin="bounds.min"
    x-bind:aria-valuemax="bounds.max"
    tabindex="0"
    {{ $attributes->class(['fi-resize-handle', 'fi-resize-handle-start' => $side === 'start']) }}
></div>
