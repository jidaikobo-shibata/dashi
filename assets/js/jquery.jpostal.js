/*jslint browser:true, devel:true */
/*global window, $, jQuery*/

/**
 * jquery.jpostal.js ver2.7
 *
 * Copyright 2014, Aoki Makoto, Ninton G.K. http://www.ninton.co.jp
 *
 * Released under the MIT license - http://en.wikipedia.org/wiki/MIT_License
 *
 * modified by jidaikobo shibata 2017-06-07 $ to jQuery
 *
 * Requirements
 * jquery.js
 */
var Jpostal = {};

Jpostal.Database = function () {
    "use strict";

    this.address = [];    // database cache
    this.map     = {};
    this.requests = {};
};

Jpostal.Database.prototype.find = function (i_postcode) {
    "use strict";

    var address = [];

    this.address.forEach(function (eachAddress) {
        if (eachAddress[0] === '_' + i_postcode) {
            address = eachAddress;
        }
    });

    return address;
};

Jpostal.Database.prototype.get = function (i_postcode) {
    "use strict";

    var address = [];

    if (i_postcode.length === 7) address = this.find(i_postcode);
    if (!address.length && i_postcode.length >= 3 && i_postcode.length <= 7) {
        address = this.find(i_postcode.substr(0, 3));
    }

    return address.length ? jQuery.extend(['', '', '', '', '', '', '', '', ''], address) : null;
};

Jpostal.Database.prototype.request = function (i_postcode, i_callback) {
    "use strict";

    var head3,
        database = this;

    head3 = i_postcode.substr(0, 3);

    if (i_postcode.length <= 2 || head3.match(/\D/) || typeof DashiPostal === 'undefined') {
        return false;
    }
    var pending = this.requests[head3];
    if (!pending) {
        if (this.getStatus(head3) !== 'none') return false;
        this.setStatus(head3, 'waiting');

        pending = this.ajax({
            dataType: 'json',
            jsonp: false,
            type: 'GET',
            url: DashiPostal.endpoint,
            headers: DashiPostal.nonce ? { 'X-Dashi-Postal-Nonce': DashiPostal.nonce } : {},
            data: { action: 'dashi_postal_lookup', prefix: head3 },
            success: function (result) {
                if (!result || result.success !== true || !Array.isArray(result.data)) return;
                database.save(result.data);
                database.setStatus(head3, 'complete');
            },
            timeout: 7000
        });
        this.requests[head3] = pending;
        pending.always(function () { delete database.requests[head3]; });
    }
    pending.done(function () {
        if (database.getStatus(head3) === 'complete') i_callback();
    });
    return pending;
};

Jpostal.Database.prototype.ajax = function (options) {
    "use strict";

    var result = jQuery.Deferred();
    function send(retryAllowed) {
        jQuery.ajax(options).done(function () {
            result.resolve();
        }).fail(function (response) {
            if (retryAllowed && response.status === 503) {
                window.setTimeout(function () { send(false); }, 1000);
                return;
            }
            result.reject();
        });
    }
    send(true);
    return result.promise();
};

Jpostal.Database.prototype.save = function (i_data) {
    "use strict";

    var that = this;

    i_data.forEach(function (rcd) {
        var postcode = rcd[0];

        if (that.map[postcode] === undefined) {
            that.address.push(rcd);
            that.map[postcode] = {state : 'complete', time : 0};
        } else if (that.map[postcode].state === 'waiting') {
            that.address.push(rcd);
            that.map[postcode].state = 'complete';
        }
    });
};

Jpostal.Database.prototype.getStatus = function (i_postcode) {
    "use strict";

    //    --------------------------------------------------
    //    #    ['_001']    ..state        .time        result
    //    --------------------------------------------------
    //    1     =undefined    -            -            none
    //    2    !=undefined    'complete'    -           complete
    //    3    !=undefined    'waiting'    <5sec        waiting
    //    4    !=undefined    'waiting'    >=5sec       none
    //    --------------------------------------------------
    var st = '',
        postcode = '_' + i_postcode,
        t_ms;

    if (this.map[postcode] === undefined) {
        // # 1
        st = 'none';

    } else if ('complete' === this.map[postcode].state) {
        // # 2
        st = 'complete';

    } else {
        t_ms = this.getTime() - this.map[postcode].time;
        if (t_ms < 5000) {
            // # 3
            st = 'waiting';

        } else {
            // # 4
            st = 'none';
        }
    }

    return st;
};

Jpostal.Database.prototype.setStatus = function (i_postcode, state) {
    "use strict";

    this.map['_' + i_postcode] = {state: state, time: this.getTime()};
};

Jpostal.Database.prototype.getTime = function () {
    "use strict";

    return (new Date()).getTime();
};

(function () {
    "use strict";

    var instance;

    Jpostal.Database.getInstance = function () {
        if (instance === undefined) {
            instance = new Jpostal.Database();
        }
        return instance;
    };
}());

Jpostal.Jpostal = function (i_JposDb) {
    "use strict";

    this.address  = '';
    this.jposDb   = i_JposDb;
    this.options  = {};
    this.postcode = '';
    this.minLen   = 3;
    this.lookup   = null;
    this.scope    = document;
};

Jpostal.Jpostal.prototype.displayAddress = function (fields) {
    "use strict";

    var that = this;

    if (this.postcode === '000info') {
        this.address[2] += ' ' + this.getScriptSrc();
    }

    fields.forEach(function (field) {
        if (!document.documentElement.contains(field.element)
            || field.element.readOnly || jQuery(field.element).is(':disabled')
            || (field.element.form && field.element.form !== that.scope)
            || (jQuery(field.element).closest('form')[0] || document) !== that.scope
            || JSON.stringify(jQuery(field.element).val()) !== field.original) return;

        var value = that.formatAddress(field.format, that.address);
        if (value === null) return;
        if (field.element.tagName === 'SELECT') {
            that.setSelectAddress(field.element, value, /^(?:%3|%p|%prefecture)$/.test(field.format));
        } else {
            jQuery(field.element).val(value);
        }
    });
};

Jpostal.Jpostal.prototype.setSelectAddress = function (element, value, matchLabel) {
    "use strict";

    var options = Array.prototype.filter.call(element.options, function (option) {
        return !option.disabled && !jQuery(option).parent('optgroup:disabled').length;
    });
    var match = options.find(function (option) { return option.value === value; });
    // 都道府県コードを値にしている選択肢では、ラベルから探す。
    if (!match && matchLabel) {
        var matches = options.filter(function (option) { return option.text.indexOf(value) >= 0; });
        if (matches.length === 1) match = matches[0];
    }
    if (match) jQuery(element).val(match.value);
};

Jpostal.Jpostal.prototype.formatAddress = function (format, address) {
    "use strict";

    var indexes = {
        '%prefecture': 1, '%3': 1, '%p': 1,
        '%city': 2, '%4': 2, '%c': 2,
        '%town': 3, '%5': 3, '%t': 3,
        '%address': 4, '%6': 4, '%a': 4,
        '%name': 5, '%7': 5, '%n': 5,
        '%8': 6, '%9': 7, '%10': 8
    };
    var missingAddressPart = false;
    var result = format.replace(/%prefecture|%address|%city|%town|%name|%10|%[3-9pctan]/g, function (placeholder) {
        var value = address[indexes[placeholder]];
        if (typeof value === 'string' && value.trim()) return value;
        missingAddressPart = true;
        return '';
    });

    return !missingAddressPart && result.trim() ? result : null;
};

Jpostal.Jpostal.prototype.getScriptSrc = function () {
    "use strict";

    var src = '',
        el_arr,
        i,
        n,
        el_src;

    el_arr = document.getElementsByTagName('script');
    n = el_arr.length;
    for (i = 0; i < n; i += 1) {
        el_src = el_arr[i].src;
        if (0 <= el_src.indexOf("jquery.jpostal.js")) {
            src = el_src;
            break;
        }
    }

    return src;
};

Jpostal.Jpostal.prototype.init = function (i_options) {
    "use strict";

    if (i_options.postcode === undefined) {
        throw new Error('postcode undefined');
    }
    if (i_options.address === undefined) {
        throw new Error('address undefined');
    }

    this.options.postcode = [];
    if (typeof i_options.postcode === 'string') {
        this.options.postcode.push(i_options.postcode);
    } else {
        this.options.postcode = i_options.postcode;
    }

    this.options.address = i_options.address;
};

Jpostal.Jpostal.prototype.main = function () {
    "use strict";

    var that = this,
        lookup,
        fields = [],
        postcode,
        requested;

    this.scanPostcode();
    if (this.postcode.length < this.minLen) {
        this.lookup = null;
        return;
    }
    postcode = this.postcode;
    // 同じ郵便番号で keyup と change が続いても、最初の照会時の入力値を保持する。
    if (this.lookup && this.lookup.postcode === postcode) return;
    lookup = { postcode: postcode };
    this.lookup = lookup;

    Object.keys(this.options.address).forEach(function (selector) {
        that.findFields(selector).each(function () {
            fields.push({
                element: this,
                format: that.options.address[selector],
                original: JSON.stringify(jQuery(this).val())
            });
        });
    });

    requested = this.jposDb.request(postcode, function () {
        that.scanPostcode();
        if (that.lookup === lookup && that.postcode === postcode) that.callback(fields);
    });
    if (requested) {
        requested.always(function () {
            if (that.lookup === lookup) that.lookup = null;
        });
    } else {
        this.lookup = null;
        if (this.jposDb.getStatus(postcode.substr(0, 3)) === 'complete') this.callback(fields);
    }
};

Jpostal.Jpostal.prototype.callback = function (fields) {
    "use strict";

    this.address = this.jposDb.get(this.postcode);
    if (this.address) this.displayAddress(fields);
};

Jpostal.Jpostal.prototype.findFields = function (selector) {
    "use strict";

    var scope = this.scope;
    return jQuery(scope).find(selector).filter(function () {
        return (!this.form || this.form === scope) && (jQuery(this).closest('form')[0] || document) === scope;
    });
};

Jpostal.Jpostal.prototype.scanPostcode = function () {
    "use strict";

    var prefix = String(this.findFields(this.options.postcode[0]).val() || ''),
        suffix,
        parts;

    this.postcode = '';
    if (this.options.postcode.length === 1) {
        parts = /^([0-9]{3})-?([0-9]{0,4})$/.exec(prefix);
        if (!parts) return;
        prefix = parts[1];
        suffix = parts[2];
    } else if (this.options.postcode.length === 2) {
        suffix = String(this.findFields(this.options.postcode[1]).val() || '');
        if (!/^[0-9]{3}$/.test(prefix) || !/^[0-9]{0,4}$/.test(suffix)) return;
    } else {
        return;
    }
    this.postcode = suffix.length === 4 ? prefix + suffix : prefix;
};

(function ($) {
    "use strict";

    $.fn.jpostal = function (i_options) {
        return this.each(function () {
            var Jpos = new Jpostal.Jpostal(Jpostal.Database.getInstance());
            Jpos.scope = $(this).closest('form')[0] || document;
            Jpos.init(i_options);
            if (Jpos.scope !== document) {
                $(Jpos.scope).on('reset', function () { Jpos.lookup = null; });
            }

            function lookup() {
                Jpos.main();
            }
            if (typeof i_options.click === 'string' && i_options.click !== '') {
                Jpos.findFields(i_options.click).on('click', lookup);
            } else {
                $(this).on('keyup change', lookup);
                if (Jpos.options.postcode.length === 2) {
                    Jpos.findFields(Jpos.options.postcode[1]).on('keyup change', lookup);
                }
            }
        });
    };
}(jQuery));
