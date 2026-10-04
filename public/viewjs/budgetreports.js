// Colours come from the theme, so the charts follow dark mode and the brand
function BudgetThemeColor(name, fallback)
{
	var value = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
	return value || fallback;
}

var BudgetColors = {
	Income: BudgetThemeColor("--g-success", "#15803d"),
	Expenses: BudgetThemeColor("--g-danger", "#d63b3b"),
	Saldo: BudgetThemeColor("--g-brand", "#0e7c66"),
	Text: BudgetThemeColor("--g-text-muted", "#6b7280"),
	Grid: BudgetThemeColor("--g-border", "#e5e7eb"),
	Surface: BudgetThemeColor("--g-surface", "#ffffff")
};

var BudgetPalette = (Chart.colorschemes && Chart.colorschemes.tableau && Chart.colorschemes.tableau.Tableau10)
	|| ["#4e79a7", "#f28e2b", "#e15759", "#76b7b2", "#59a14f", "#edc948", "#b07aa1", "#ff9da7", "#9c755f", "#bab0ac"];

Chart.defaults.global.defaultFontColor = BudgetColors.Text;
Chart.defaults.global.defaultFontFamily = getComputedStyle(document.body).fontFamily;

function BudgetQuery(extra)
{
	var query = BudgetPage.BudgetId === "all" ? "" : "budget_id=" + BudgetPage.BudgetId + "&";
	return query + extra;
}

// Axis labels without cents and with k for thousands - the exact amounts are
// in the tooltips and the table
function BudgetShortAmount(value)
{
	if (Math.abs(value) >= 1000)
	{
		return (value / 1000).toLocaleString(GrocyBudget.Locale, { maximumFractionDigits: 1 }) + "k";
	}

	return value.toLocaleString(GrocyBudget.Locale, { maximumFractionDigits: 0 });
}

// --- income and expenses per month -------------------------------------

var monthlyChart = null;

function BudgetLoadMonthly()
{
	var months = $("#monthly-range").val();

	Grocy.Api.Get("budget/reports/monthly?" + BudgetQuery("to=" + BudgetPage.CurrentMonth + "&months=" + months),
		BudgetRenderMonthly,
		function (xhr)
		{
			GrocyBudget.ShowError(xhr);
		}
	);
}

function BudgetRenderMonthly(rows)
{
	// Months before the first booking are not "nothing happened", they are
	// "not tracked yet" - leaving them out keeps the averages honest
	var first = rows.findIndex(function (row)
	{
		return row.income !== 0 || row.expenses !== 0;
	});
	rows = first === -1 ? [] : rows.slice(first);

	$("#monthly-report").toggleClass("d-none", rows.length === 0);
	$("#monthly-empty").toggleClass("d-none", rows.length > 0);

	if (rows.length === 0)
	{
		return;
	}

	var labels = rows.map(function (row)
	{
		return moment(row.month + "-01").format("MMM YY");
	});

	var datasets = [
		{
			type: "line",
			label: __t("Balance of the month"),
			data: rows.map(function (row) { return row.saldo; }),
			borderColor: BudgetColors.Saldo,
			backgroundColor: BudgetColors.Saldo,
			borderWidth: 2,
			pointRadius: 3,
			fill: false,
			lineTension: 0.25,
			order: 0
		},
		{
			label: __t("Income"),
			data: rows.map(function (row) { return row.income; }),
			backgroundColor: BudgetColors.Income,
			order: 1
		},
		{
			label: __t("Expenses"),
			data: rows.map(function (row) { return row.expenses; }),
			backgroundColor: BudgetColors.Expenses,
			order: 1
		}
	];

	if (monthlyChart)
	{
		monthlyChart.data.labels = labels;
		monthlyChart.data.datasets = datasets;
		monthlyChart.update();
	}
	else
	{
		monthlyChart = new Chart("monthly-chart", {
			type: "bar",
			data: { labels: labels, datasets: datasets },
			options: {
				maintainAspectRatio: false,
				animation: { duration: 300 },
				legend: {
					position: "bottom",
					labels: { boxWidth: 12, padding: 16 }
				},
				tooltips: {
					mode: "index",
					intersect: false,
					callbacks: {
						label: function (item, data)
						{
							return data.datasets[item.datasetIndex].label + ": " + GrocyBudget.Format(item.yLabel);
						}
					}
				},
				scales: {
					xAxes: [{
						gridLines: { display: false },
						ticks: { maxRotation: 0, autoSkipPadding: 8 }
					}],
					yAxes: [{
						gridLines: { color: BudgetColors.Grid, zeroLineColor: BudgetColors.Grid },
						ticks: {
							beginAtZero: true,
							maxTicksLimit: 6,
							callback: BudgetShortAmount
						}
					}]
				}
			}
		});
	}

	// Averages and the table (newest month first)
	var totals = rows.reduce(function (sum, row)
	{
		sum.income += row.income;
		sum.expenses += row.expenses;
		sum.saldo += row.saldo;
		return sum;
	}, { income: 0, expenses: 0, saldo: 0 });

	var count = Math.max(rows.length, 1);
	var stat = function (label, value, cssClass)
	{
		return $('<div class="budget-report-stat"></div>')
			.append($('<div class="budget-tile-label"></div>').text(label))
			.append($('<div class="budget-report-stat-value"></div>').addClass(cssClass || "").text(GrocyBudget.Format(value)));
	};

	$("#monthly-stats").empty()
		.append(stat(__t("Average income"), totals.income / count, "budget-positive"))
		.append(stat(__t("Average expenses"), totals.expenses / count))
		.append(stat(__t("Average balance"), totals.saldo / count, totals.saldo < 0 ? "budget-negative" : ""));

	var body = $("#monthly-table tbody").empty();
	rows.slice().reverse().forEach(function (row)
	{
		$("<tr></tr>")
			.append($("<td></td>").text(moment(row.month + "-01").format("MMM YYYY")))
			.append($('<td class="text-right budget-positive"></td>').text(GrocyBudget.Format(row.income)))
			.append($('<td class="text-right"></td>').text(GrocyBudget.Format(row.expenses)))
			.append($('<td class="text-right font-weight-bold"></td>').addClass(row.saldo < 0 ? "budget-negative" : "").text(GrocyBudget.Format(row.saldo)))
			.appendTo(body);
	});

	$("<tr class='budget-report-total'></tr>")
		.append($("<td></td>").text(__t("Total")))
		.append($('<td class="text-right budget-positive"></td>').text(GrocyBudget.Format(totals.income)))
		.append($('<td class="text-right"></td>').text(GrocyBudget.Format(totals.expenses)))
		.append($('<td class="text-right"></td>').addClass(totals.saldo < 0 ? "budget-negative" : "").text(GrocyBudget.Format(totals.saldo)))
		.appendTo(body);
}

// --- expenses by category ----------------------------------------------

var categoryChart = null;

function BudgetCategoryPeriod(key)
{
	var now = moment();

	switch (key)
	{
		case "last-month":
			return [now.clone().subtract(1, "month").startOf("month"), now.clone().subtract(1, "month").endOf("month")];
		case "last-3-months":
			return [now.clone().subtract(2, "months").startOf("month"), now.clone().endOf("month")];
		case "this-year":
			return [now.clone().startOf("year"), now.clone().endOf("year")];
		case "last-12-months":
			return [now.clone().subtract(11, "months").startOf("month"), now.clone().endOf("month")];
		default:
			return [now.clone().startOf("month"), now.clone().endOf("month")];
	}
}

function BudgetLoadCategories()
{
	var period = BudgetCategoryPeriod($("#category-range").val());

	Grocy.Api.Get("budget/reports/categories?" + BudgetQuery("from=" + period[0].format("YYYY-MM-DD") + "&to=" + period[1].format("YYYY-MM-DD")),
		BudgetRenderCategories,
		function (xhr)
		{
			GrocyBudget.ShowError(xhr);
		}
	);
}

function BudgetRenderCategories(rows)
{
	var total = rows.reduce(function (sum, row) { return sum + row.amount; }, 0);
	var colors = rows.map(function (row, index) { return BudgetPalette[index % BudgetPalette.length]; });

	$(".budget-category-report").toggleClass("d-none", rows.length === 0);
	$("#category-empty").toggleClass("d-none", rows.length > 0);

	var data = {
		labels: rows.map(function (row) { return row.name; }),
		datasets: [{
			data: rows.map(function (row) { return row.amount; }),
			backgroundColor: colors,
			borderColor: BudgetColors.Surface,
			borderWidth: 2
		}]
	};

	var centerLabels = [
		{ text: GrocyBudget.Format(total), font: { size: 20, weight: "bold" }, color: BudgetThemeColor("--g-text", "#111827") },
		{ text: __t("Total"), color: BudgetColors.Text }
	];

	if (categoryChart)
	{
		categoryChart.data = data;
		categoryChart.options.plugins.doughnutlabel.labels = centerLabels;
		categoryChart.update();
	}
	else
	{
		categoryChart = new Chart("category-chart", {
			type: "doughnut",
			data: data,
			options: {
				maintainAspectRatio: false,
				cutoutPercentage: 64,
				animation: { duration: 300 },
				legend: { display: false },
				tooltips: {
					callbacks: {
						label: function (item, chartData)
						{
							var value = chartData.datasets[0].data[item.index];
							return chartData.labels[item.index] + ": " + GrocyBudget.Format(value);
						}
					}
				},
				plugins: {
					doughnutlabel: { labels: centerLabels },
					// Registered globally for the stock report - here the
					// list below the chart says what each part is
					outlabels: false
				}
			}
		});
	}

	var list = $("#category-list").empty();
	rows.forEach(function (row, index)
	{
		var share = total > 0 ? row.amount / total : 0;

		$('<div class="budget-row"></div>')
			.append($("<span></span>")
				.append($('<span class="budget-legend-swatch"></span>').css("background-color", colors[index]))
				.append(document.createTextNode(row.name)))
			.append($('<span class="budget-row-amount"></span>')
				.text(GrocyBudget.Format(row.amount))
				.append($('<span class="budget-row-share"></span>').text(share.toLocaleString(GrocyBudget.Locale, { style: "percent", maximumFractionDigits: 0 }))))
			.appendTo(list);
	});
}

$("#monthly-range").on("change", BudgetLoadMonthly);
$("#category-range").on("change", BudgetLoadCategories);

BudgetLoadMonthly();
BudgetLoadCategories();
