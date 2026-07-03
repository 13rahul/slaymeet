/**
 * SlayMeet gallery layout — Teams-style grid with dock-safe area (Wave 1).
 */
(function (global) {
    'use strict';

    const GAP = 8;
    const PAD = 12;
    const DOCK_FALLBACK = 96;

    function getDockReserve() {
        const dock = document.querySelector('.bottom-dock');
        const dockH = dock ? dock.offsetHeight : DOCK_FALLBACK;
        return dockH + 28;
    }

    function getTopbarReserve() {
        const bar = document.querySelector('.sm-topbar');
        return bar ? bar.offsetHeight : 56;
    }

    function scoreTile(tile) {
        let s = 0;
        if (tile.classList.contains('speaking')) s += 50;
        if (!tile.classList.contains('video-off')) s += 100;
        if (tile.dataset.pinned === '1') s += 1000;
        return s;
    }

    function sortTiles(tiles) {
        const myId = parseInt((global.SlayMeetConfig && global.SlayMeetConfig.userId) || 0, 10);
        return tiles.slice().sort(function (a, b) {
            const diff = scoreTile(b) - scoreTile(a);
            if (diff !== 0) return diff;
            const aLocal = parseInt(a.dataset.userId, 10) === myId;
            const bLocal = parseInt(b.dataset.userId, 10) === myId;
            if (aLocal && !bLocal) return 1;
            if (!aLocal && bLocal) return -1;
            return parseInt(a.dataset.userId, 10) - parseInt(b.dataset.userId, 10);
        });
    }

    function placeTilesInGrid(sorted, cols, tileW, tileH) {
        const count = sorted.length;
        const lastRowIndex = Math.ceil(count / cols) - 1;
        const tilesInLastRow = count - lastRowIndex * cols;

        sorted.forEach(function (tile, i) {
            const row = Math.floor(i / cols) + 1;
            let col = (i % cols) + 1;

            if (tilesInLastRow > 0 && tilesInLastRow < cols && row === lastRowIndex + 1) {
                const startCol = Math.floor((cols - tilesInLastRow) / 2) + 1;
                col = startCol + (i - lastRowIndex * cols);
            }

            tile.style.gridColumn = String(col);
            tile.style.gridRow = String(row);
            tile.style.width = '100%';
            tile.style.height = '100%';
            tile.style.justifySelf = '';
        });
    }

    function pickGalleryLayout(count, availW, availH) {
        if (count <= 0) return { cols: 1, rows: 1 };
        if (count === 1) return { cols: 1, rows: 1 };
        if (count === 2) {
            return availW >= availH ? { cols: 2, rows: 1 } : { cols: 1, rows: 2 };
        }
        if (count === 3) return { cols: 2, rows: 2, centerLast: true };
        if (count === 4) return { cols: 2, rows: 2 };
        if (count <= 6) return { cols: 3, rows: 2 };
        if (count <= 9) return { cols: 3, rows: 3 };
        return { cols: 4, rows: Math.ceil(count / 4) };
    }

    function clearTilePlacement(tile) {
        tile.style.transform = '';
        tile.style.width = '';
        tile.style.height = '';
        tile.style.gridColumn = '';
        tile.style.gridRow = '';
        tile.style.justifySelf = '';
        tile.style.alignSelf = '';
        tile.style.zIndex = '';
        tile.style.borderRadius = '';
        tile.style.overflow = '';
    }

    function applyDmOneToOneLayout(tiles, stage, stageWidth, stageHeight) {
        const myId = parseInt((global.SlayMeetConfig && global.SlayMeetConfig.userId) || 0, 10);
        const local = tiles.find(function (t) { return parseInt(t.dataset.userId, 10) === myId; });
        const remote = tiles.find(function (t) { return parseInt(t.dataset.userId, 10) !== myId; });
        const dockReserve = getDockReserve();
        const pad = 16;
        const availW = stageWidth - pad * 2;
        const availH = stageHeight - pad * 2 - dockReserve;

        stage.classList.remove('video-stage--gallery', 'video-stage--spotlight');
        stage.classList.add('video-stage--dm');
        stage.style.display = '';
        stage.style.gridTemplateColumns = '';
        stage.style.gridTemplateRows = '';
        stage.style.gap = '';
        stage.style.padding = '';

        tiles.forEach(clearTilePlacement);

        if (remote) {
            let mainW = Math.min(availW, availH * 16 / 9);
            let mainH = mainW * 9 / 16;
            if (mainH > availH) {
                mainH = availH;
                mainW = mainH * 16 / 9;
            }
            const mainX = (stageWidth - mainW) / 2;
            const mainY = (stageHeight - mainH - dockReserve) / 2 + pad * 0.5;
            remote.style.width = mainW + 'px';
            remote.style.height = mainH + 'px';
            remote.style.transform = 'translate(' + mainX + 'px, ' + mainY + 'px)';
            remote.style.zIndex = '2';
            const av = remote.querySelector('.avatar-circle');
            if (av) av.style.display = '';
        }

        if (local) {
            const pipW = Math.min(180, availW * 0.28);
            const pipH = pipW * 9 / 16;
            local.style.width = pipW + 'px';
            local.style.height = pipH + 'px';
            local.style.transform = 'translate(' + (stageWidth - pipW - pad - 8) + 'px, ' + (stageHeight - pipH - pad - dockReserve) + 'px)';
            local.style.zIndex = '4';
            local.style.borderRadius = '10px';
            local.style.overflow = 'hidden';
        }
    }

    function applySpotlightLayout(stage, tiles, spotlightUserId, stageWidth, stageHeight) {
        const dockReserve = getDockReserve();
        const padding = PAD;
        const availWidth = stageWidth - padding * 2;
        const availHeight = stageHeight - padding * 2 - dockReserve;

        const spotlightTile = stage.querySelector('.tile[data-user-id="' + spotlightUserId + '"]');
        if (!spotlightTile) return false;

        const thumbs = tiles.filter(function (t) { return t !== spotlightTile; });
        const gap = GAP;
        let thumbH = 72;
        let thumbW = thumbH * 16 / 9;

        if (thumbs.length > 0) {
            const required = thumbs.length * thumbW + (thumbs.length - 1) * gap;
            if (required > availWidth) {
                thumbW = (availWidth - (thumbs.length - 1) * gap) / thumbs.length;
                thumbH = thumbW * 9 / 16;
            }
        }

        const thumbStripHeight = thumbs.length > 0 ? thumbH + gap : 0;
        const mainAvailHeight = Math.max(120, availHeight - thumbStripHeight);

        stage.classList.remove('video-stage--gallery', 'video-stage--dm');
        stage.classList.add('video-stage--spotlight');
        stage.style.display = '';
        stage.style.gridTemplateColumns = '';
        stage.style.gridTemplateRows = '';
        tiles.forEach(clearTilePlacement);

        let mainW = Math.min(availWidth, mainAvailHeight * 16 / 9);
        let mainH = mainW * 9 / 16;
        if (mainH > mainAvailHeight) {
            mainH = mainAvailHeight;
            mainW = mainH * 16 / 9;
        }

        const mainX = (stageWidth - mainW) / 2;
        const mainY = padding + (mainAvailHeight - mainH) / 2;
        spotlightTile.style.width = mainW + 'px';
        spotlightTile.style.height = mainH + 'px';
        spotlightTile.style.transform = 'translate(' + mainX + 'px, ' + mainY + 'px)';
        spotlightTile.style.zIndex = '3';

        if (thumbs.length > 0) {
            const totalThumbW = thumbs.length * thumbW + (thumbs.length - 1) * gap;
            const startX = (stageWidth - totalThumbW) / 2;
            const y = stageHeight - dockReserve - thumbH - padding;
            thumbs.forEach(function (tile, i) {
                tile.style.width = thumbW + 'px';
                tile.style.height = thumbH + 'px';
                tile.style.transform = 'translate(' + (startX + i * (thumbW + gap)) + 'px, ' + y + 'px)';
                tile.style.zIndex = '4';
            });
        }
        return true;
    }

    function applyGalleryGrid(stage, tiles, stageWidth, stageHeight) {
        const dockReserve = getDockReserve();
        const topReserve = getTopbarReserve();
        const availW = stageWidth - PAD * 2;
        const availH = stageHeight - PAD * 2 - dockReserve - topReserve * 0.25;

        const sorted = sortTiles(tiles);
        const count = sorted.length;
        const layout = pickGalleryLayout(count, availW, availH);
        const cols = layout.cols;
        const rows = layout.rows;

        stage.classList.remove('video-stage--dm', 'video-stage--spotlight');
        stage.classList.add('video-stage--gallery');

        const gap = GAP;
        const cellW = (availW - (cols - 1) * gap) / cols;
        const cellH = (availH - (rows - 1) * gap) / rows;

        let tileW = cellW;
        let tileH = tileW * 9 / 16;
        if (tileH > cellH) {
            tileH = cellH;
            tileW = tileH * 16 / 9;
        }

        stage.style.display = 'grid';
        stage.style.gridTemplateColumns = 'repeat(' + cols + ', ' + tileW + 'px)';
        stage.style.gridTemplateRows = 'repeat(' + rows + ', ' + tileH + 'px)';
        stage.style.gap = gap + 'px';
        stage.style.padding = (topReserve * 0.15 + PAD) + 'px ' + PAD + 'px ' + (dockReserve + PAD) + 'px';
        stage.style.placeContent = 'center';
        stage.style.alignContent = 'center';

        sorted.forEach(function (tile) {
            clearTilePlacement(tile);
        });

        if (layout.centerLast && count === 3) {
            sorted[0].style.gridColumn = '1';
            sorted[0].style.gridRow = '1';
            sorted[1].style.gridColumn = '2';
            sorted[1].style.gridRow = '1';
            sorted[2].style.gridColumn = '1 / -1';
            sorted[2].style.gridRow = '2';
            sorted[2].style.justifySelf = 'center';
            sorted[2].style.width = tileW + 'px';
            sorted[2].style.height = tileH + 'px';
        } else {
            placeTilesInGrid(sorted, cols, tileW, tileH);
        }

        const frag = document.createDocumentFragment();
        sorted.forEach(function (tile) { frag.appendChild(tile); });
        stage.appendChild(frag);
    }

    function calculateSmartGrid() {
        const stage = document.getElementById('video-stage');
        if (!stage) return;

        const tiles = Array.from(stage.querySelectorAll('.tile'));
        if (tiles.length === 0) return;

        const stageWidth = stage.clientWidth;
        const stageHeight = stage.clientHeight;
        const cfg = global.SlayMeetConfig || {};
        const spotlightId = global.activeScreenShareUserId || null;

        if (cfg.isDmCall && tiles.length <= 2) {
            applyDmOneToOneLayout(tiles, stage, stageWidth, stageHeight);
            return;
        }

        if (spotlightId && applySpotlightLayout(stage, tiles, spotlightId, stageWidth, stageHeight)) {
            return;
        }

        applyGalleryGrid(stage, tiles, stageWidth, stageHeight);
    }

    function refresh() {
        calculateSmartGrid();
    }

    global.SlaymeetGallery = {
        refresh: refresh,
        calculate: calculateSmartGrid,
    };

    global.refreshGridLayout = refresh;
    global.calculateSmartGrid = calculateSmartGrid;

    global.addEventListener('resize', function () {
        refresh();
    });
})(typeof window !== 'undefined' ? window : this);
