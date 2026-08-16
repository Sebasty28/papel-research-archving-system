/**
 * Global Input Validation - Prevents obvious gibberish/random text
 * Uses relaxed thresholds to avoid false positives on academic content
 */
(function() {
  'use strict';
  
  function isGibberish(text) {
    if (!text || text.length < 10) return false;
    var cleaned = text.toLowerCase().replace(/[^a-z]/g, '');
    if (cleaned.length < 10) return false;
    
    // Only flag extreme consonant runs (10+ in a row)
    if (/[bcdfghjklmnpqrstvwxyz]{10,}/.test(cleaned)) return true;
    
    // Only flag excessive repeated characters (8+ times)
    if (/(.)\1{7,}/.test(cleaned)) return true;
    
    // Only flag extremely low vowel ratio (< 8% vowels in long text)
    var vowels = cleaned.match(/[aeiou]/g) || [];
    if (vowels.length / cleaned.length < 0.08 && cleaned.length > 20) return true;
    
    // Keyboard mashing patterns (only the most obvious ones)
    if (/asdfgh|qwerty|zxcvbn/i.test(text)) return true;
    
    return false;
  }
  
  function validateInput(input) {
    var value = input.value.trim();
    
    // Skip validation for non-text inputs, textareas, short values, and readonly fields
    if (input.type === 'password' || input.type === 'email' || 
        input.type === 'number' || input.type === 'file' ||
        input.type === 'hidden' || input.type === 'checkbox' ||
        input.type === 'radio' || input.tagName === 'TEXTAREA' ||
        input.readOnly || input.disabled || value.length < 10) {
      return true;
    }
    
    if (isGibberish(value)) {
      // Show a subtle warning, not an aggressive red error
      input.style.borderColor = '#fbbf24';
      input.style.backgroundColor = '#fffbeb';
      return false;
    } else {
      input.style.borderColor = '';
      input.style.backgroundColor = '';
      return true;
    }
  }
  
  document.addEventListener('DOMContentLoaded', function() {
    var inputs = document.querySelectorAll('input[type="text"], input:not([type])');
    
    inputs.forEach(function(input) {
      input.addEventListener('blur', function() {
        validateInput(this);
      });
      
      input.addEventListener('focus', function() {
        this.style.borderColor = '';
        this.style.backgroundColor = '';
      });
    });
  });
})();
