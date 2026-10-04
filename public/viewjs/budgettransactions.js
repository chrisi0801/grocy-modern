var budgetTransactionsTable = $("#budget-transactions-table").DataTable({
	"order": [[3, "desc"]],
	"columnDefs": [
		{ "orderable": false, "targets": 0 },
		{ "searchable": false, "targets": 0 }
	].concat($.fn.dataTable.defaults.columnDefs)
});
$("#budget-transactions-table tbody").removeClass("d-none");
budgetTransactionsTable.columns.adjust().draw();

$("#search").on("keyup", Delay(function ()
{
	budgetTransactionsTable.search($(this).val().accentNeutralise()).draw();
}, Grocy.FormFocusDelay));

// The category is a filter of the page (the totals above the table follow it)
$("#category-filter").on("change", function ()
{
	var url = "/budget/transactions?budget=" + BudgetPage.BudgetId + "&month=" + BudgetPage.Month;

	if ($(this).val())
	{
		url += "&category=" + $(this).val();
	}

	window.location.href = U(url);
});

$(document).on("click", ".budget-transaction-delete-button", function (e)
{
	e.preventDefault();

	var button = $(e.currentTarget);
	var question = button.attr("data-is-transfer") === "1"
		? __t('Are you sure you want to delete the transfer "%s"? Both sides of it are removed.', button.attr("data-transaction-name"))
		: __t('Are you sure you want to delete the transaction "%s"?', button.attr("data-transaction-name"));

	GrocyBudget.ConfirmDelete(question, "budget/transactions/" + button.attr("data-transaction-id"), window.location.href);
});
