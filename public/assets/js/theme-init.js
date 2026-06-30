(function () {
    "use strict";
    var t = localStorage.getItem("op-theme");
    if (t === "light" || t === "dark") {
        document.documentElement.setAttribute("data-theme", t);
    }
})();
