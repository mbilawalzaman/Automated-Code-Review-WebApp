import $ from 'jquery';
import DataTable from 'datatables.net-dt';
import 'datatables.net-dt/css/dataTables.dataTables.css';

window.$ = window.jQuery = $;
window.DataTable = DataTable;

const FILTER_SELECTOR =
    '#filter-category, #filter-severity, #filter-status, #filter-repository, #filter-branch, #filter-author, #filter-date-from, #filter-date-to';

function reloadDataTables() {
    $('[data-datatable]').each(function () {
        const table = $(this);
        if ($.fn.DataTable.isDataTable(table)) {
            table.DataTable().ajax.reload();
        }
    });
}

window.reloadDataTables = reloadDataTables;

document.addEventListener('DOMContentLoaded', () => {
    $.fn.dataTable.ext.errMode = 'none';

    $('[data-datatable]').each(function () {
        const table = $(this);
        const url = table.data('ajax-url');
        const columnsJson = table.data('columns');

        if (url && columnsJson && !$.fn.DataTable.isDataTable(table)) {
            const dt = table.DataTable({
                processing: true,
                serverSide: true,
                ajax: {
                    url: url,
                    data: function (d) {
                        d.category = $('#filter-category').val();
                        d.severity = $('#filter-severity').val();
                        d.status = $('#filter-status').val();
                        d.repository_id = $('#filter-repository').val();
                        d.branch = $('#filter-branch').val();
                        d.author = $('#filter-author').val();
                        d.date_from = $('#filter-date-from').val();
                        d.date_to = $('#filter-date-to').val();
                    }
                },
                autoWidth: false,
                pageLength: 25,
                dom: '<"flex justify-end mb-3"f>t<"flex items-center justify-between mt-4 border-t border-gray-200 pt-4"<"flex items-center gap-4"li>p>',
                columns: columnsJson,
                drawCallback: function () {
                    if (window.Alpine?.initTree) {
                        window.Alpine.initTree(table[0]);
                    }
                },
            });

            table.data('instance', dt);
        }
    });

    // Apply filters as soon as a control changes (no Filter button).
    $(document).on('change', FILTER_SELECTOR, reloadDataTables);

    // Text filters: debounce while typing so Branch/Developer filter without a submit button.
    let textFilterTimer = null;
    $(document).on('input', '#filter-branch, #filter-author', function () {
        clearTimeout(textFilterTimer);
        textFilterTimer = setTimeout(reloadDataTables, 300);
    });
});

