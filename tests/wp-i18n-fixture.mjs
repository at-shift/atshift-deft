import { readFileSync } from "node:fs";
import { runInNewContext } from "node:vm";

export function installI18n(window) {
  for (const file of ["hooks.js", "i18n.js"]) {
    runInNewContext(readFileSync(new URL(`../.local/wordpress/wp-includes/js/dist/${file}`, import.meta.url), "utf8"), { window, console });
  }
  return window.wp.i18n;
}
