var budgetRecurringTable = $("#budget-recurring-table").DataTable({
	"order": [[4, "asc"]],
	"columnDefs": [
		{ "orderable": false, "targets": 0 },
		{ "searchable": false, "targets": 0 }
	].concat($.fn.dataTable.defaults.columnDefs)
});
$("#budget-recurring-table tbody").removeClass("d-none");
budgetRecurringTable.columns.adjust().draw();

$(document).on("click", ".budget-recurring-delete-button", function (e)
{
	e.preventDefault();

	var button = $(e.currentTarget);
	GrocyBudget.ConfirmDelete(
		__t('Are you sure you want to delete the recurring transaction "%s"? What it has booked so far stays.', button.attr("data-recurring-name")),
		"budget/recurring/" + button.attr("data-recurring-id"),
		window.location.href
	);
});
