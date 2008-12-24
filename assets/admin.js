UIControl.deploy("input[type=submit]", function(button) {
	var lang = Symphony.Language;

	DOM.Event.addListener(button, "click", function(event) {
		var withSelected = DOM.select("select[name=with-selected]");
		try {  if (withSelected[0].options[withSelected[0].selectedIndex].value == 'delete') withSelected = 'delete' } catch (e) { withSelected = ''; }
		var	temp = DOM.select("tbody input").map(function(input, position) {
			if (input.checked) {
				if (input.name.match(/^delete\[[\w_]+\]$/) || withSelected == 'delete') return input;
			}
			return false;
		});
		var inputs = [];
		for (var i = 0; i < temp.length; i++) {
			if (typeof(temp[i]) == 'object') inputs.push(temp[i]);
		}

		if (inputs.length > 0 && !confirm(lang.CONFIRM_MANY.replace("{$action}", 'delete').replace("{$count}", inputs.length))) event.preventDefault();
	});
});
