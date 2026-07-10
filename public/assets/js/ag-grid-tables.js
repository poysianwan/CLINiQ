(function () {
    function readGridJson(grid, selector, fallback) {
        const node = grid.querySelector(selector);
        if (!node) return fallback;

        try {
            return JSON.parse(node.textContent || '');
        } catch (error) {
            console.warn('Unable to parse AG Grid data for', grid.id, error);
            return fallback;
        }
    }

    function htmlRenderer(params) {
        return params.value || '';
    }

    function normalizeColumns(columns, shouldFitColumns) {
        return columns.map((column) => {
            const nextColumn = { ...column };
            if (nextColumn.cellRenderer === 'html') {
                nextColumn.cellRenderer = htmlRenderer;
            }

            if (shouldFitColumns) {
                const basis = Number(nextColumn.flex || nextColumn.width || nextColumn.minWidth || 140);
                nextColumn.flex = Math.max(0.7, Math.min(2.25, basis / 140));
                nextColumn.minWidth = Math.min(Number(nextColumn.minWidth || 92), 140);
                delete nextColumn.width;
            }

            return nextColumn;
        });
    }

    function makeEmptyOverlay(title, text) {
        return `
            <div class="cliniq-grid-empty">
                <span class="material-symbols-outlined">table_view</span>
                <strong>${escapeHtml(title || 'No records found')}</strong>
                <p>${escapeHtml(text || 'There is nothing to show here yet.')}</p>
            </div>
        `;
    }

    function initGrid(grid) {
        if (!window.agGrid || !window.agGrid.createGrid) {
            grid.innerHTML = '<div class="cliniq-grid-empty"><strong>Table failed to load</strong><p>Please check your connection and refresh the page.</p></div>';
            return;
        }

        const rowData = readGridJson(grid, '[data-grid-rows]', []);
        const pageSize = Number(grid.dataset.pageSize || 25);
        const shouldFitColumns = grid.dataset.fitColumns !== 'false';
        const columnDefs = normalizeColumns(readGridJson(grid, '[data-grid-columns]', []), shouldFitColumns);

        function fitColumns(api) {
            if (shouldFitColumns && api && api.sizeColumnsToFit) {
                api.sizeColumnsToFit();
            }
        }

        const gridOptions = {
            rowData,
            columnDefs,
            defaultColDef: {
                sortable: true,
                filter: true,
                resizable: true,
                minWidth: shouldFitColumns ? 76 : 130,
                flex: 1,
                wrapHeaderText: true,
                autoHeaderHeight: true
            },
            pagination: false,
            animateRows: true,
            suppressCellFocus: true,
            rowHeight: 70,
            headerHeight: 48,
            overlayNoRowsTemplate: makeEmptyOverlay(grid.dataset.emptyTitle, grid.dataset.emptyText)
        };

        if (shouldFitColumns) {
            gridOptions.suppressHorizontalScroll = true;
            gridOptions.onGridReady = (params) => window.requestAnimationFrame(() => fitColumns(params.api));
            gridOptions.onGridSizeChanged = (params) => fitColumns(params.api);
            gridOptions.onFirstDataRendered = (params) => fitColumns(params.api);
        }

        const api = window.agGrid.createGrid(grid, gridOptions);
        fitColumns(api);

        if (shouldFitColumns && window.ResizeObserver) {
            const observer = new ResizeObserver(() => fitColumns(api));
            observer.observe(grid);
            if (grid.parentElement) {
                observer.observe(grid.parentElement);
            }
        }

        const searchInput = grid.dataset.searchInput ? document.getElementById(grid.dataset.searchInput) : null;

        if (searchInput) {
            searchInput.addEventListener('input', () => {
                if (api.setGridOption) {
                    api.setGridOption('quickFilterText', searchInput.value);
                } else if (api.setQuickFilter) {
                    api.setQuickFilter(searchInput.value);
                }
            });
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('[data-ag-grid]').forEach(initGrid);
    });
})();
