(() => {
  "use strict";
  if (!window.HTMLDialogElement?.prototype.showModal) return;
  const labels = window.sfBehaviorLabels || {
    enlarge: "Enlarge image",
    close: "Close image",
  };
  function initialize() {
    for (const img of document.querySelectorAll(
      ".sf-article img[data-sf-zoom]",
    )) {
      if (img.dataset.sfZoomReady || img.hidden || img.closest("a,button"))
        continue;
      img.dataset.sfZoomReady = "true";
      const enhance = () => {
        if (!img.isConnected || !img.naturalWidth) return;
        const trigger = document.createElement("button");
        trigger.type = "button";
        trigger.className = "sf-zoom-trigger";
        trigger.setAttribute("aria-label", labels.enlarge + ": " + img.alt);
        trigger.setAttribute("aria-haspopup", "dialog");
        img.replaceWith(trigger);
        trigger.append(img);
        trigger.addEventListener("click", () => {
          const dialog = document.createElement("dialog"),
            close = document.createElement("button"),
            figure = document.createElement("figure"),
            large = document.createElement("img");
          dialog.className = "sf-zoom-dialog";
          dialog.setAttribute("aria-label", img.alt || labels.enlarge);
          close.className = "sf-zoom-close";
          close.type = "button";
          close.textContent = "\u00d7";
          close.setAttribute("aria-label", labels.close);
          close.autofocus = true;
          large.src = img.currentSrc || img.src;
          large.alt = img.alt;
          figure.append(large);
          if (img.dataset.sfZoom === "caption") {
            const caption = document.createElement("figcaption");
            caption.textContent = img.alt;
            figure.append(caption);
          }
          dialog.append(close, figure);
          document.body.append(dialog);
          close.addEventListener("click", () => dialog.close());
          dialog.addEventListener("click", (event) => {
            if (event.target === dialog) {
              const r = dialog.getBoundingClientRect();
              if (
                event.clientX < r.left ||
                event.clientX > r.right ||
                event.clientY < r.top ||
                event.clientY > r.bottom
              )
                dialog.close();
            }
          });
          dialog.addEventListener(
            "close",
            () => {
              dialog.remove();
              if (trigger.isConnected) trigger.focus();
            },
            { once: true },
          );
          dialog.showModal();
        });
      };
      if (img.complete) enhance();
      else img.addEventListener("load", enhance, { once: true });
    }
  }
  if (document.readyState === "loading")
    document.addEventListener("DOMContentLoaded", initialize, { once: true });
  else initialize();
})();
