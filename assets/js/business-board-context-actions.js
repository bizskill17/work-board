(function(){
  'use strict';

  function closestTask(el){
    return el && el.closest ? el.closest('.bb-task') : null;
  }

  function isExcludedTarget(el){
    if(!el || !el.closest) return false;
    return !!el.closest('input,select,textarea,a,button,[contenteditable="true"],.bb-drag-handle,.bb-task-drag-handle,.bb-task-grip,.bb-inline-pop,.bb-popover,.bb-modal');
  }

  // Desktop: allow the main WorkBoard handler to receive right-clicks from\n  // any non-form surface inside a task card. Do not intercept or re-dispatch\n  // the event here; synthetic contextmenu forwarding could be ignored by some\n  // desktop browsers. The delegated handler in business-board.js is the source\n  // of truth for opening Task Options.\n  // Touch: make the whole non-interactive card surface a reliable long-press target.
  // Existing context-menu logic remains the single source of truth for the action sheet.
  var timer = null, startX = 0, startY = 0, activeCard = null;
  function clearLongPress(){
    if(timer){ clearTimeout(timer); timer = null; }
    activeCard = null;
  }

  document.addEventListener('pointerdown', function(e){
    if(e.pointerType !== 'touch' && e.pointerType !== 'pen') return;
    var card = closestTask(e.target);
    if(!card || isExcludedTarget(e.target)) return;
    clearLongPress();
    activeCard = card;
    startX = e.clientX; startY = e.clientY;
    timer = setTimeout(function(){
      if(!activeCard) return;
      var ev = new MouseEvent('contextmenu', {
        bubbles:true, cancelable:true, view:window,
        clientX:startX, clientY:startY, button:2, buttons:2
      });
      Object.defineProperty(ev, '__bbCardForwarded', {value:true});
      activeCard.dispatchEvent(ev);
      clearLongPress();
    }, 560);
  }, true);

  document.addEventListener('pointermove', function(e){
    if(!timer) return;
    if(Math.abs(e.clientX-startX) > 10 || Math.abs(e.clientY-startY) > 10) clearLongPress();
  }, true);
  document.addEventListener('pointerup', clearLongPress, true);
  document.addEventListener('pointercancel', clearLongPress, true);
  document.addEventListener('scroll', clearLongPress, true);
})();
