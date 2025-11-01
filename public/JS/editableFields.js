(function(){
    // Lightweight bridge around existing global edit functions
    function safe(fnName){
        return typeof window[fnName] === 'function' ? window[fnName] : function(){};
    }

    var api = {
        enable: function(){ safe('enableEditMode')(); },
        disable: function(){ safe('disableEditMode')(); },
        refresh: function(){
            // Re-apply current state based on any visible editable fields
            var anyEditable = document.querySelector('.editable-field');
            if (!anyEditable) return;
            // If either toggle indicates ON, enable; else disable
            var viewToggle = document.getElementById('edit-mode-toggle-view');
            var mainToggle = document.getElementById('edit-mode-toggle');
            var isOn = (viewToggle && viewToggle.checked) || (mainToggle && mainToggle.checked);
            if (isOn) { this.enable(); } else { this.disable(); }
        }
    };

    window.EditableFields = api;
})();


