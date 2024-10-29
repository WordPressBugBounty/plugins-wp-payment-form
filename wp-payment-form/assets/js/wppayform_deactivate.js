/******/ (function(modules) { // webpackBootstrap
/******/ 	// The module cache
/******/ 	var installedModules = {};
/******/
/******/ 	// The require function
/******/ 	function __webpack_require__(moduleId) {
/******/
/******/ 		// Check if module is in cache
/******/ 		if(installedModules[moduleId]) {
/******/ 			return installedModules[moduleId].exports;
/******/ 		}
/******/ 		// Create a new module (and put it into the cache)
/******/ 		var module = installedModules[moduleId] = {
/******/ 			i: moduleId,
/******/ 			l: false,
/******/ 			exports: {}
/******/ 		};
/******/
/******/ 		// Execute the module function
/******/ 		modules[moduleId].call(module.exports, module, module.exports, __webpack_require__);
/******/
/******/ 		// Flag the module as loaded
/******/ 		module.l = true;
/******/
/******/ 		// Return the exports of the module
/******/ 		return module.exports;
/******/ 	}
/******/
/******/
/******/ 	// expose the modules object (__webpack_modules__)
/******/ 	__webpack_require__.m = modules;
/******/
/******/ 	// expose the module cache
/******/ 	__webpack_require__.c = installedModules;
/******/
/******/ 	// define getter function for harmony exports
/******/ 	__webpack_require__.d = function(exports, name, getter) {
/******/ 		if(!__webpack_require__.o(exports, name)) {
/******/ 			Object.defineProperty(exports, name, { enumerable: true, get: getter });
/******/ 		}
/******/ 	};
/******/
/******/ 	// define __esModule on exports
/******/ 	__webpack_require__.r = function(exports) {
/******/ 		if(typeof Symbol !== 'undefined' && Symbol.toStringTag) {
/******/ 			Object.defineProperty(exports, Symbol.toStringTag, { value: 'Module' });
/******/ 		}
/******/ 		Object.defineProperty(exports, '__esModule', { value: true });
/******/ 	};
/******/
/******/ 	// create a fake namespace object
/******/ 	// mode & 1: value is a module id, require it
/******/ 	// mode & 2: merge all properties of value into the ns
/******/ 	// mode & 4: return value when already ns object
/******/ 	// mode & 8|1: behave like require
/******/ 	__webpack_require__.t = function(value, mode) {
/******/ 		if(mode & 1) value = __webpack_require__(value);
/******/ 		if(mode & 8) return value;
/******/ 		if((mode & 4) && typeof value === 'object' && value && value.__esModule) return value;
/******/ 		var ns = Object.create(null);
/******/ 		__webpack_require__.r(ns);
/******/ 		Object.defineProperty(ns, 'default', { enumerable: true, value: value });
/******/ 		if(mode & 2 && typeof value != 'string') for(var key in value) __webpack_require__.d(ns, key, function(key) { return value[key]; }.bind(null, key));
/******/ 		return ns;
/******/ 	};
/******/
/******/ 	// getDefaultExport function for compatibility with non-harmony modules
/******/ 	__webpack_require__.n = function(module) {
/******/ 		var getter = module && module.__esModule ?
/******/ 			function getDefault() { return module['default']; } :
/******/ 			function getModuleExports() { return module; };
/******/ 		__webpack_require__.d(getter, 'a', getter);
/******/ 		return getter;
/******/ 	};
/******/
/******/ 	// Object.prototype.hasOwnProperty.call
/******/ 	__webpack_require__.o = function(object, property) { return Object.prototype.hasOwnProperty.call(object, property); };
/******/
/******/ 	// __webpack_public_path__
/******/ 	__webpack_require__.p = "/";
/******/
/******/
/******/ 	// Load entry module and return exports
/******/ 	return __webpack_require__(__webpack_require__.s = 1);
/******/ })
/************************************************************************/
/******/ ({

/***/ "./resources/admin/wppayform_deactivate.js":
/*!*************************************************!*\
  !*** ./resources/admin/wppayform_deactivate.js ***!
  \*************************************************/
/*! no static exports found */
/***/ (function(module, exports) {

jQuery(document).ready(function ($) {
  var deactivateButton = $('a[id="deactivate-wp-payment-form"]');
  function openForm() {
    document.getElementById("wpf-feedback").style.display = "block";
  }
  function toggleErrorMessage() {
    var display = arguments.length > 0 && arguments[0] !== undefined ? arguments[0] : "block";
    document.querySelector(".validation-message").style.display = display;
  }
  function closeForm() {
    document.getElementById("wpf-feedback").style.display = "none";
  }
  jQuery('#wpf-btn-close').on('click', function (e) {
    closeForm();
  });
  jQuery('#wpf-skip-deactivate').attr('href', deactivateButton.attr('href'));
  deactivateButton.on('click', function (e) {
    e.preventDefault();
    openForm();
    jQuery('input[name="reason"]').on('change', function () {
      if (jQuery('input[name="reason"]:checked').length > 0) {
        toggleErrorMessage("none");
      }
    });
    var wpfFeedbackForm = document.getElementById("wpf-feedback");
    wpfFeedbackForm.addEventListener("submit", function (e) {
      e.preventDefault();
      var description = document.getElementById("wpf-feedback-description").value;
      var reasons = document.getElementsByName('reason');
      var paymattic_deactivation_nonce = document.getElementsByName('paymattic_deactivation_nonce');
      var nonceVal = jQuery(paymattic_deactivation_nonce).attr('value');
      var values = [];
      for (var i = 0; i < reasons.length; i++) {
        if (reasons[i].checked) {
          values.push(reasons[i].value);
        }
      }
      if (values.length === 0) {
        toggleErrorMessage();
        return;
      }
      var formData = {
        message: description,
        others: values,
        nonce: nonceVal
      };
      makeAjaxRequest(JSON.stringify(formData));
    });
    function makeAjaxRequest(formData) {
      jQuery.ajax({
        url: "https://wordpress.test/?feedback-collector=yes&route=collect-feedback",
        type: "POST",
        data: formData,
        success: function success(data) {
          document.getElementById("wpf-feedback").style.display = "none";
          var redirectUrl = deactivateButton.attr('href');
          window.location.href = redirectUrl;
        },
        error: function error(_error) {
          document.getElementById("wpf-feedback").style.display = "none";
          var redirectUrl = deactivateButton.attr('href');
          window.location.href = redirectUrl;
        }
      });
    }
  });
});

/***/ }),

/***/ 1:
/*!*******************************************************!*\
  !*** multi ./resources/admin/wppayform_deactivate.js ***!
  \*******************************************************/
/*! no static exports found */
/***/ (function(module, exports, __webpack_require__) {

module.exports = __webpack_require__(/*! /Volumes/projects/wordpress/wp-content/plugins/wp-payment-form/resources/admin/wppayform_deactivate.js */"./resources/admin/wppayform_deactivate.js");


/***/ })

/******/ });