(function() {
    'use strict';

    const jMake = {};
    jMake.functions = {};

    // Fetch content for a path and inject it into the matching element.
    // Uses $.parseHTML with scripts disabled to prevent XSS.
    jMake.functions.fetchAndInject = function(path) {
        $.get(path)
            .done(function(data) {
                const clean = $.parseHTML(data, document, false);
                $('*[data-path="' + path + '"]').prepend(clean);
            })
            .fail(function() {
                console.error('j-make: failed to load content for path "' + path + '"');
            });
    };

    // Walk up the DOM collecting data-key values to build the directory path.
    jMake.functions.getNodeTreePath = function(el) {
        const parts = [];
        let node = el;
        while ((node = node.parentNode)) {
            if (!node.dataset || !node.dataset.key) {
                break;
            }
            parts.push(node.dataset.key);
        }
        return parts.reverse();
    };

    // Count preceding siblings to determine positional index.
    jMake.functions.getNodeIndex = function(el) {
        let index = 0;
        let sibling = el;
        while ((sibling = sibling.previousElementSibling) !== null) {
            ++index;
        }
        return index;
    };

    // Create a DOM element, set its key/path attributes, and fetch its content.
    // Returns the new element so callers can use it as a parent for nested arrays.
    jMake.functions.buildElement = function(tagName, parentEl) {
        const element = document.createElement(tagName);
        parentEl.appendChild(element);

        const treePath = jMake.functions.getNodeTreePath(element);
        const nodeIndex = jMake.functions.getNodeIndex(element);
        const key = tagName + '_' + nodeIndex;

        element.dataset.key = key;
        element.className = 'j-make';

        const basePath = treePath.length > 0 ? 'body/' : 'body';
        element.dataset.path = basePath + treePath.join('/') + '/' + key;

        jMake.functions.fetchAndInject(element.dataset.path);
        return element;
    };

    // Recursively process a JSON array.
    // Strings create elements; arrays nest under the most recently created element.
    jMake.functions.buildFromArray = function(arr, parentEl) {
        let lastElement = parentEl;
        $(arr).each(function(index, val) {
            if (typeof val === 'string') {
                lastElement = jMake.functions.buildElement(val, parentEl);
            } else if (Array.isArray(val)) {
                jMake.functions.buildFromArray(val, lastElement);
            } else {
                console.error('j-make: unexpected value of type "' + typeof val + '" in body.json');
            }
        });
    };

    document.addEventListener('DOMContentLoaded', function() {
        $.getJSON('body.json')
            .done(function(data) {
                jMake.functions.buildFromArray(data, document.body);
            })
            .fail(function() {
                console.error('j-make: failed to load body.json');
            });
    });
}());