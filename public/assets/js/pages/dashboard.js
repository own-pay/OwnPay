(function() {
    const dateRange = document.getElementById("date-range");
    if (dateRange) {
        dateRange.addEventListener("change", function() {
            location.href = "?range=" + encodeURIComponent(this.value);
        });
    }
})();
