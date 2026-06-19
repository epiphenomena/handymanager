// autocomplete.js - Small, dependency-free autocomplete dropdown.
//
// Native <datalist> is unreliable across browsers (and is suppressed by
// autocomplete="off" in Chrome), so this is the same custom dropdown the
// tech New-Task page uses. It only suggests - freeform entry is allowed.
//
//   HMAutocomplete.attach(inputEl, {
//       getItems: function () { return ['Smith', 'Garcia']; }, // current pool
//       onSelect: function (value) {}                          // optional
//   });
(function () {
    function attach(input, opts) {
        opts = opts || {};
        var getItems = opts.getItems || function () { return []; };
        var onSelect = opts.onSelect || function () {};
        var max = opts.max || 50;

        // Wrap the input so the dropdown can be positioned against it
        var wrap = document.createElement('div');
        wrap.className = 'hm-ac-wrap';
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);

        var box = document.createElement('div');
        box.className = 'hm-ac-list';
        box.style.display = 'none';
        wrap.appendChild(box);

        var current = [];
        var activeIndex = -1;

        function hide() { box.style.display = 'none'; activeIndex = -1; }

        function choose(value) {
            input.value = value;
            hide();
            // Let prefill / validation listeners react
            input.dispatchEvent(new Event('input', { bubbles: true }));
            onSelect(value);
            input.focus();
        }

        function show() {
            var q = input.value.trim().toLowerCase();
            var items = getItems() || [];
            var matches = (q === ''
                ? items
                : items.filter(function (v) { return v.toLowerCase().indexOf(q) !== -1; })
            ).slice(0, max);

            box.innerHTML = '';
            current = matches;
            activeIndex = -1;
            if (!matches.length) { hide(); return; }

            matches.forEach(function (value) {
                var row = document.createElement('div');
                row.className = 'hm-ac-item';
                row.textContent = value;
                // mousedown fires before blur and only on a tap (not while
                // scrolling the list) - works for mouse and touch alike
                row.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    choose(value);
                });
                box.appendChild(row);
            });
            box.style.display = 'block';
        }

        function highlight() {
            var rows = box.querySelectorAll('.hm-ac-item');
            rows.forEach(function (r, i) { r.classList.toggle('active', i === activeIndex); });
            if (activeIndex >= 0 && rows[activeIndex]) {
                rows[activeIndex].scrollIntoView({ block: 'nearest' });
            }
        }

        input.addEventListener('focus', show);
        input.addEventListener('input', show);
        input.addEventListener('keydown', function (e) {
            var count = box.querySelectorAll('.hm-ac-item').length;
            if (e.key === 'ArrowDown') {
                e.preventDefault(); activeIndex = Math.min(activeIndex + 1, count - 1); highlight();
            } else if (e.key === 'ArrowUp') {
                e.preventDefault(); activeIndex = Math.max(activeIndex - 1, -1); highlight();
            } else if (e.key === 'Enter') {
                if (activeIndex >= 0 && current[activeIndex] !== undefined) { e.preventDefault(); choose(current[activeIndex]); }
            } else if (e.key === 'Escape') {
                hide();
            }
        });

        document.addEventListener('click', function (e) {
            if (!wrap.contains(e.target)) hide();
        });
    }

    window.HMAutocomplete = { attach: attach };
})();
