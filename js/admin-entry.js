import { configureApiEndpoint } from "./api-client.js";
import { AdminPanel } from "./admin.js";

function mountAdminNoticeRegion() {
  const region = document.getElementById("adminPageNotices");
  const notify = (kind, message) => {
    if (!region || typeof message !== "string" || !message.trim()) return;
    const notice = document.createElement("div");
    notice.className = `admin-page-notice admin-page-notice-${kind}`;
    notice.setAttribute("role", kind === "error" ? "alert" : "status");
    notice.textContent = message;
    region.prepend(notice);
    window.setTimeout(() => notice.remove(), 8000);
  };
  return {
    success: (message) => notify("success", message),
    error: (message) => notify("error", message),
    info: (message) => notify("info", message),
  };
}

document.addEventListener("DOMContentLoaded", async () => {
  configureApiEndpoint("api.php");
  window.notificationSystem = mountAdminNoticeRegion();
  window.adminPanel = new AdminPanel();
  await window.adminPanel.bootStandaloneWorkspace();
});
