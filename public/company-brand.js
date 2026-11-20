/* Pick a backing for transparent customer artwork without changing its colors. */
(() => {
    const backgrounds = new Map();

    function chooseBackground(image) {
        if (!image.naturalWidth || !image.naturalHeight) return;
        if (!backgrounds.has(image.currentSrc)) {
            const canvas = document.createElement('canvas');
            canvas.width = canvas.height = 40;
            const context = canvas.getContext('2d');
            if (!context) return;
            try {
                context.drawImage(image, 0, 0, 40, 40);
                const pixels = context.getImageData(0, 0, 40, 40).data;
                let opaque = 0;
                let light = 0;
                let transparent = 0;
                for (let index = 0; index < pixels.length; index += 4) {
                    const alpha = pixels[index + 3] / 255;
                    if (alpha < .1) transparent++;
                    opaque += alpha;
                    const brightness = .213 * pixels[index] + .715 * pixels[index + 1] + .072 * pixels[index + 2];
                    if (brightness > 180) light += alpha;
                }
                backgrounds.set(image.currentSrc, transparent > 80 && light > opaque * .66 ? 'dark' : 'light');
            } catch {
                return;
            }
        }
        image.parentElement.dataset.background = backgrounds.get(image.currentSrc);
    }

    document.querySelectorAll('.tenant-brand-mark img').forEach(image => {
        if (image.complete) chooseBackground(image);
        else image.addEventListener('load', () => chooseBackground(image), { once: true });
    });
})();
