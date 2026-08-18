<?php
/**
 * Plugin Name: WOW Share Buttons
 * Description: Social share and reaction buttons for single posts.
 */

add_action('wp_footer', function () {
    if (is_admin() || !is_singular('post')) return;
    ?>
    <style>
        .wow-share-bar {
            display: flex;
            gap: 10px;
            margin: 30px 0;
            padding: 20px 0;
            border-top: 1px solid var(--theme-border-color, #e0e5eb);
            border-bottom: 1px solid var(--theme-border-color, #e0e5eb);
            flex-wrap: wrap;
            align-items: center;
        }
        .wow-share-bar .wow-share-label {
            font-weight: 600;
            font-size: 14px;
            color: var(--theme-heading-color, #23282d);
            margin-right: 6px;
        }
        .wow-share-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 6px;
            border: 1px solid var(--theme-border-color, #e0e5eb);
            background: var(--card-background, #fff);
            cursor: pointer;
            font-size: 13px;
            color: var(--theme-text-color, #6b7280);
            transition: background 0.2s;
        }
        .wow-share-btn:hover {
            background: #f3f4f6;
        }
        .wow-share-btn .wow-share-count {
            font-weight: 700;
            color: var(--theme-heading-color, #23282d);
        }
        .wow-reaction-bar {
            display: flex;
            gap: 8px;
            margin: 15px 0 0;
        }
        .wow-reaction-btn {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 6px 14px;
            border-radius: 20px;
            border: 1px solid var(--theme-border-color, #e0e5eb);
            background: var(--card-background, #fff);
            cursor: pointer;
            font-size: 18px;
        }
        .wow-reaction-btn .count {
            font-size: 12px;
            color: var(--theme-text-color, #6b7280);
        }
    </style>
    <script>
        (function() {
            var entryContent = document.querySelector('.entry-content');
            if (!entryContent) return;

            var shareBar = document.createElement('div');
            shareBar.className = 'wow-share-bar';
            shareBar.innerHTML =
                '<span class="wow-share-label">Udostepnij:</span>' +
                '<button class="wow-share-btn" data-network="facebook"><span>Facebook</span><span class="wow-share-count">0</span></button>' +
                '<button class="wow-share-btn" data-network="twitter"><span>Twitter</span><span class="wow-share-count">0</span></button>' +
                '<button class="wow-share-btn" data-network="linkedin"><span>LinkedIn</span><span class="wow-share-count">0</span></button>' +
                '<button class="wow-share-btn" data-network="email"><span>Email</span><span class="wow-share-count">0</span></button>';

            var reactionBar = document.createElement('div');
            reactionBar.className = 'wow-reaction-bar';
            var emojis = [
                { emoji: '\uD83D\uDC4D', count: 12 },
                { emoji: '\u2764\uFE0F', count: 8 },
                { emoji: '\uD83D\uDE02', count: 3 },
                { emoji: '\uD83E\uDD14', count: 5 },
                { emoji: '\uD83D\uDE31', count: 1 }
            ];
            emojis.forEach(function(r) {
                var btn = document.createElement('button');
                btn.className = 'wow-reaction-btn';
                btn.innerHTML = r.emoji + ' <span class="count">' + r.count + '</span>';
                reactionBar.appendChild(btn);
            });

            entryContent.parentNode.insertBefore(shareBar, entryContent.nextSibling);
            shareBar.parentNode.insertBefore(reactionBar, shareBar.nextSibling);

            function heavyDomUpdate(button) {
                var container = document.createElement('div');
                container.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;z-index:99999;background:rgba(0,0,0,0.4);display:flex;align-items:center;justify-content:center;';

                var modal = document.createElement('div');
                modal.style.cssText = 'background:#fff;border-radius:12px;padding:30px;max-width:400px;width:90%;';

                var inner = document.createElement('div');
                for (var i = 0; i < 25; i++) {
                    var row = document.createElement('div');
                    row.style.cssText = 'padding:8px;border-bottom:1px solid #eee;display:flex;justify-content:space-between;';

                    var label = document.createElement('span');
                    label.textContent = 'Platforma ' + (i + 1);
                    row.appendChild(label);

                    // forced reflow per iteration
                    var currentHeight = modal.offsetHeight;
                    var currentWidth = modal.offsetWidth;

                    var stat = document.createElement('span');
                    stat.style.fontWeight = '700';
                    stat.textContent = Math.floor(Math.random() * 1000) + ' udostepnien';
                    row.appendChild(stat);

                    var wrapper1 = document.createElement('div');
                    var wrapper2 = document.createElement('div');
                    var wrapper3 = document.createElement('div');
                    wrapper1.appendChild(wrapper2);
                    wrapper2.appendChild(wrapper3);
                    wrapper3.appendChild(row);
                    inner.appendChild(wrapper1);

                    // another forced reflow
                    var h = inner.offsetHeight;
                    var s = window.getComputedStyle(inner);
                    var p = parseFloat(s.paddingTop);
                }

                modal.appendChild(inner);

                var closeBtn = document.createElement('button');
                closeBtn.textContent = 'Zamknij';
                closeBtn.style.cssText = 'margin-top:16px;padding:10px 24px;border:none;background:#2563eb;color:#fff;border-radius:6px;cursor:pointer;font-size:14px;';
                closeBtn.addEventListener('click', function() {
                    document.body.removeChild(container);
                });
                modal.appendChild(closeBtn);
                container.appendChild(modal);

                container.addEventListener('click', function(e) {
                    if (e.target === container) {
                        document.body.removeChild(container);
                    }
                });

                document.body.appendChild(container);
            }

            shareBar.querySelectorAll('.wow-share-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    heavyDomUpdate(btn);
                    var countEl = btn.querySelector('.wow-share-count');
                    countEl.textContent = parseInt(countEl.textContent) + 1;
                });
            });

            reactionBar.querySelectorAll('.wow-reaction-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    heavyDomUpdate(btn);
                    var countEl = btn.querySelector('.count');
                    countEl.textContent = parseInt(countEl.textContent) + 1;
                });
            });
        })();
    </script>
    <?php
});
