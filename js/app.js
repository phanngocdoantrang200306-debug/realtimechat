

const state = {
    userId: null,
    nickname: "",
    currentRoom: null,
    rooms: [],
    members: [],
    messages: []
};


// ================================
// DOM HELPERS
// ================================

const $ = (selector) => document.querySelector(selector);

const welcomeScreen = $("#welcomeScreen");
const lobbyScreen = $("#lobbyScreen");
const chatScreen = $("#chatScreen");
const roomGrid = $("#roomGrid");
const roomModal = $("#roomModal");
const toast = $("#toast");

function showToast(message) {
    toast.textContent = message;
    toast.classList.add("show");

    clearTimeout(showToast.timer);

    showToast.timer = setTimeout(() => {
        toast.classList.remove("show");
    }, 2200);
}

function initials(name) {
    return String(name)
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map(part => part[0] || "")
        .join("")
        .toUpperCase();
}

function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, char => ({
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        '"': "&quot;",
        "'": "&#039;"
    }[char]));
}

async function authRequest(endpoint, options = {}) {
    const response = await fetch(
        `backend/api/${endpoint}`,
        {
            credentials: "same-origin",
            cache: "no-store",
            ...options
        }
    );

    let data;

    try {
        data = await response.json();
    } catch {
        throw new Error("Phản hồi từ server không hợp lệ.");
    }

    if (!response.ok || !data.success) {
        throw new Error(data.message || "Yêu cầu thất bại.");
    }

    return data;
}

function setAuthenticatedUser(user) {
    state.userId = Number(user.id);
    state.nickname = user.nickname;
}

// ================================
// WEBSOCKET
// ================================

let websocket = null;
let roomCounts = {};
let currentRoomPassword = "";

// AUTO RECONNECT
let reconnectTimer = null;
let reconnectAttempts = 0;
let shouldReconnect = false;

const MAX_RECONNECT_DELAY = 10000;
const BASE_RECONNECT_DELAY = 1000;


function updateConnectionStatus(status) {
    const text = $("#connectionText");
    const dot = $("#connectionDot");

    if (!text || !dot) return;

    switch (status) {
        case "connected":
            text.textContent = "Connected";
            dot.style.background = "#22c55e";
            break;

        case "reconnecting":
            text.textContent = "Reconnecting...";
            dot.style.background = "#f59e0b";
            break;

        case "disconnected":
            text.textContent = "Disconnected";
            dot.style.background = "#ef4444";
            break;
    }
}


function scheduleReconnect() {
    if (!shouldReconnect || !state.userId) return;

    if (reconnectTimer !== null) return;

    if (
        websocket &&
        (websocket.readyState === WebSocket.OPEN ||
         websocket.readyState === WebSocket.CONNECTING)
    ) {
        return;
    }

    const delay = Math.min(
        BASE_RECONNECT_DELAY * Math.pow(2, reconnectAttempts),
        MAX_RECONNECT_DELAY
    );

    reconnectAttempts++;

    updateConnectionStatus("reconnecting");

    console.log(
        `WebSocket reconnect attempt ${reconnectAttempts} in ${delay}ms`
    );

    reconnectTimer = setTimeout(() => {
        reconnectTimer = null;

        if (shouldReconnect && state.userId) {
            connectWebSocket();
        }
    }, delay);
}



function connectWebSocket() {

    // 1. Kiểm tra người dùng đã đăng nhập
    if (!state.userId || !shouldReconnect) {
        console.log("WebSocket chưa được phép kết nối");
        return;
    }

    // 2. Tránh tạo kết nối trùng
    if (
        websocket &&
        (
            websocket.readyState === WebSocket.OPEN ||
            websocket.readyState === WebSocket.CONNECTING
        )
    ) {
        return;
    }

    // 3. Hủy lịch reconnect cũ
    if (reconnectTimer !== null) {
        clearTimeout(reconnectTimer);
        reconnectTimer = null;
    }

    updateConnectionStatus("reconnecting");

    // 4. TẠO WebSocket trước khi gán các sự kiện
    const isLocal = ["localhost", "127.0.0.1"]
    .includes(window.location.hostname);

    const wsUrl = isLocal
        ? "ws://127.0.0.1:8080/chat"
        : `wss://${window.location.host}/chat`;

    const ws = new WebSocket(wsUrl);
    websocket = ws;

    // 5. Kết nối thành công
    ws.onopen = () => {
        if (websocket !== ws) return;

        console.log("WebSocket connected!");

        reconnectAttempts = 0;
        updateConnectionStatus("connected");

        // Cập nhật số người trong các phòng
        ws.send(JSON.stringify({
            type: "get_room_counts"
        }));

        // Tự động vào lại phòng sau khi reconnect
        if (state.currentRoom) {
            ws.send(JSON.stringify({
                type: "join_room",
                room_id: state.currentRoom.id,
                username: state.nickname,
                password: currentRoomPassword
            }));
        }
    };

    // 6. Nhận dữ liệu từ server
    
    ws.onmessage = (event) => {
        if (websocket !== ws) return;

        let data;

        try {
            data = JSON.parse(event.data);
        } catch (error) {
            console.error("Invalid WebSocket JSON:", error);
            return;
        }

        console.log("WebSocket received:", data);

        switch (data.type) {

            // Cập nhật số lượng người trong các phòng
            case "room_counts":
                roomCounts = data.counts || {};
                renderRooms();
                break;

            // Khi có phòng mới được tạo
            case "room_created":
                loadRooms();
                break;

            // Khi không thể tham gia phòng
            case "join_denied":
                alert(data.message || "Không thể tham gia phòng.");
                break;

            // Nhận lịch sử tin nhắn
            case "chat_history":
                state.messages = (data.messages || []).map(message => ({
                    id: message.id,
                    user_id: message.user_id,
                    username: message.username,
                    text: message.content,
                    time: message.time
                }));
                renderMessages();
                break;

            // Nhận tin nhắn mới
            case "new_message":
                state.messages.push({
                    id: data.id,
                    user_id: data.user_id,
                    username: data.username,
                    text: data.content,
                    time: data.time
                });
                renderMessages();
                break;

            // Khi có người tham gia phòng
            case "user_joined":
                console.log("User joined:", data);
                break;

            // Khi có người rời phòng
            case "user_left":
                console.log("User left:", data);
                break;

            // Hiển thị người đang nhập
            case "typing":
                showTypingIndicator(data.username, data.is_typing);
                break;

            // Cập nhật danh sách người online
            
            case "online_users": {
                const users = data.users || data.members || data.online_users || [];

                state.members = users.map(user =>
                    typeof user === "string"
                        ? user
                        : user.username || user.nickname
                ).filter(Boolean);

                renderMembers();
                break;
            }


            // Tin nhắn được chỉnh sửa
            case "message_edited": {
                const message = state.messages.find(
                    m => Number(m.id) === Number(data.message_id)
                );

                if (message) {
                    message.text = data.content;
                    renderMessages();
                }
                break;
            }

            // Tin nhắn bị xóa
            case "message_deleted":
                state.messages = state.messages.filter(
                    m => Number(m.id) !== Number(data.message_id)
                );
                renderMessages();
                break;

            // Lỗi từ server
            case "error":
                console.error("Server error:", data.message);
                break;

            default:
                console.log("Unknown WebSocket event:", data.type);
        }
    };


    // 7. Mất kết nối
    ws.onclose = (event) => {
        if (websocket !== ws) return;

        console.log(
            "WebSocket closed:",
            event.code,
            event.reason
        );

        websocket = null;

        if (shouldReconnect && state.userId) {
            updateConnectionStatus("reconnecting");
            scheduleReconnect();
        } else {
            updateConnectionStatus("disconnected");
        }
    };

    // 8. Lỗi kết nối
    ws.onerror = (error) => {
        if (websocket !== ws) return;

        console.error("WebSocket error:", error);

        // Không reconnect ở đây.
        // onclose sẽ gọi scheduleReconnect().
    };
}

function showTypingIndicator(username, isTyping) {
    const indicator = $("#typingIndicator");

    if (!indicator) return;

    if (isTyping) {
        indicator.textContent = `${username} đang soạn tin nhắn...`;
        indicator.classList.add("show");
    } else {
        indicator.textContent = "";
        indicator.classList.remove("show");
    }
}

function sendWebSocket(data) {
    if (
        !websocket ||
        websocket.readyState !== WebSocket.OPEN
    ) {
        showToast("WebSocket chưa kết nối.");
        return false;
    }

    websocket.send(JSON.stringify(data));
    return true;
}

// ================================
// RENDER ROOMS
// ================================

async function loadRooms() {
    try {
        const response = await fetch(
            "backend/api/rooms.php",
            { cache: "no-store" }
        );

        if (!response.ok) {
            throw new Error("HTTP " + response.status);
        }

        const data = await response.json();

        if (!data.success || !Array.isArray(data.rooms)) {
            throw new Error("Dữ liệu phòng không hợp lệ");
        }

        // Cập nhật danh sách phòng từ MySQL
        state.rooms = data.rooms;

        // Hiển thị lại danh sách phòng
        renderRooms();

        console.log("Rooms loaded from MySQL:", state.rooms);

    } catch (error) {
        console.error("Load rooms error:", error);
        showToast("Không thể tải danh sách phòng.");
    }
}

function renderRooms() {
    roomGrid.innerHTML = state.rooms.map(room => `
        <article class="room-card">
            <div class="room-card-head">
                <h3>${escapeHtml(room.name)}</h3>
                <span class="room-tag">
                    ${room.private ? "🔒 Riêng tư" : "🌐 Công khai"}
                </span>
            </div>

            <p>${escapeHtml(room.description)}</p>

            <p>● ${roomCounts[room.id] ?? 0} người đang online</p>

            <button
                class="primary-btn"
                data-room-id="${room.id}"
            >
                Tham gia phòng →
            </button>
        </article>
    `).join("");
}

// ================================
// OPEN LOBBY
// ================================



function openLobby() {
    welcomeScreen.classList.add("hidden");
    chatScreen.classList.add("hidden");
    lobbyScreen.classList.remove("hidden");

    $("#welcomeUser").textContent =
        `Xin chào, ${state.nickname}`;

    renderRooms();
    loadRooms();

    if (websocket?.readyState === WebSocket.OPEN) {
    sendWebSocket({
            type: "get_room_counts"
        });
    }

    // Duy trì kết nối WebSocket ngay cả khi ở Lobby
    
    shouldReconnect = true;

    if (
        !websocket ||
        websocket.readyState === WebSocket.CLOSED
    ) {
        if (reconnectTimer === null) {
            connectWebSocket();
        }
    }

}



// ================================
// OPEN CHAT ROOM
// ================================


function openChat(room, password = "") {
    state.currentRoom = room;
    currentRoomPassword = password;

    state.members = [];
    state.messages = [];

    lobbyScreen.classList.add("hidden");
    welcomeScreen.classList.add("hidden");
    chatScreen.classList.remove("hidden");

    $("#chatRoomName").textContent = room.name;
    $("#roomAvatar").textContent = room.private ? "🔒" : "#";

    renderMembers();
    renderMessages();

    if (
        !websocket ||
        websocket.readyState === WebSocket.CLOSED
    ) {
        connectWebSocket();
    } else if (websocket.readyState === WebSocket.OPEN) {
        sendWebSocket({
            type: "join_room",
            room_id: room.id,
            username: state.nickname,
            password: currentRoomPassword
        });
    }
}


// ================================
// RENDER MEMBERS
// ================================

function renderMembers() {
    $("#memberCount").textContent = state.members.length;

    $("#chatRoomMeta").textContent =
        `${state.members.length} người online`;

    $("#memberList").innerHTML = state.members.map(name => `
        <div class="member">
            <span class="avatar">
                ${escapeHtml(initials(name))}
            </span>

            <span>
                ${escapeHtml(name)}
                ${name === state.nickname ? " (bạn)" : ""}
            </span>

            <span class="online-dot"></span>
        </div>
    `).join("");
}

// ================================
// RENDER MESSAGES
// ================================


function renderMessages() {
    const list = $("#messageList");

    list.innerHTML = state.messages.map(message => {

        if (message.system) {
            return `
                <div class="system-message">
                    ${escapeHtml(message.text)}
                </div>
            `;
        }

        const mine = Number(message.user_id) === Number(state.userId)
            && message.user_id != null;

        return `
            <div class="message ${mine ? "mine" : ""}">
                <span class="avatar">
                    ${escapeHtml(initials(message.username))}
                </span>

                <div class="message-body">
                    <div class="message-meta">
                        ${escapeHtml(message.username)}
                        · ${escapeHtml(message.time || "")}
                        ${message.edited ? " · Đã chỉnh sửa" : ""}
                    </div>

                    <div class="bubble">${escapeHtml(message.text)}</div>

                    ${mine && message.id != null ? `
                        <div class="message-actions">
                            <button type="button"
                                class="edit-message-btn"
                                data-message-id="${Number(message.id)}">
                                ✏️ Sửa
                            </button>

                            <button type="button"
                                class="delete-message-btn"
                                data-message-id="${Number(message.id)}">
                                🗑️ Xóa
                            </button>
                        </div>
                    ` : ""}
                </div>
            </div>
        `;
    }).join("");

    list.scrollTop = list.scrollHeight;
}


$("#messageList").addEventListener("click", event => {

    const editButton = event.target.closest(".edit-message-btn");
    const deleteButton = event.target.closest(".delete-message-btn");

    if (!state.currentRoom) return;

    if (editButton) {
        const messageId = Number(editButton.dataset.messageId);

        const message = state.messages.find(
            item => Number(item.id) === messageId
        );

        if (!message ||
            Number(message.user_id) !== Number(state.userId)) {
            showToast("Bạn không có quyền sửa tin nhắn này.");
            return;
        }

        const newContent = prompt(
            "Chỉnh sửa tin nhắn:",
            message.text
        );

        if (newContent === null) return;

        const content = newContent.trim();

        if (!content || content.length > 2000) {
            showToast("Nội dung phải có từ 1 đến 2000 ký tự.");
            return;
        }

        if (content === message.text) return;

        sendWebSocket({
            type: "edit_message",
            message_id: messageId,
            content: content
        });
    }

    if (deleteButton) {
        const messageId = Number(deleteButton.dataset.messageId);

        const message = state.messages.find(
            item => Number(item.id) === messageId
        );

        if (!message ||
            Number(message.user_id) !== Number(state.userId)) {
            showToast("Bạn không có quyền xóa tin nhắn này.");
            return;
        }

        if (!confirm("Bạn có chắc muốn xóa tin nhắn này?")) {
            return;
        }

        sendWebSocket({
            type: "delete_message",
            message_id: messageId
        });
    }
});


// ================================
// REGISTER / LOGIN
// ================================

let authMode = "login";

const authForm = $("#authForm");
const authTitle = $("#authTitle");
const authSubmitBtn = $("#authSubmitBtn");
const authToggleBtn = $("#authToggleBtn");
const authPassword = $("#authPassword");

function updateAuthMode() {
    const isRegister = authMode === "register";

    authTitle.textContent = isRegister
        ? "Đăng ký tài khoản"
        : "Đăng nhập";

    authSubmitBtn.textContent = isRegister
        ? "Đăng ký"
        : "Đăng nhập";

    authToggleBtn.textContent = isRegister
        ? "Đã có tài khoản? Đăng nhập"
        : "Chưa có tài khoản? Đăng ký";

    authPassword.autocomplete = isRegister
        ? "new-password"
        : "current-password";
}

authToggleBtn.addEventListener("click", () => {
    authMode = authMode === "login"
        ? "register"
        : "login";

    authPassword.value = "";
    updateAuthMode();
});

authForm.addEventListener("submit", async event => {
    event.preventDefault();

    const nickname = $("#nickname").value.trim();
    const password = authPassword.value;

    if (nickname.length < 2 || nickname.length > 24) {
        showToast("Biệt danh phải có từ 2 đến 24 ký tự.");
        return;
    }

    if (
        new TextEncoder().encode(password).length < 8 ||
        new TextEncoder().encode(password).length > 72
    ) {
        showToast("Mật khẩu phải có từ 8 đến 72 byte.");
        return;
    }

    authSubmitBtn.disabled = true;

    try {
        const endpoint = authMode === "register"
            ? "register.php"
            : "login.php";

        const data = await authRequest(endpoint, {
            method: "POST",
            headers: {
                "Content-Type": "application/json"
            },
            body: JSON.stringify({
                nickname,
                password
            })
        });

        if (authMode === "register") {
            showToast("Đăng ký thành công! Hãy đăng nhập.");

            authMode = "login";
            authPassword.value = "";
            updateAuthMode();
            authPassword.focus();

            return;
        }

        setAuthenticatedUser(data.user);

        authPassword.value = "";

        openLobby();

        showToast("Đăng nhập thành công!");

    } catch (error) {
        console.error("Authentication error:", error);
        showToast(error.message);

    } finally {
        authSubmitBtn.disabled = false;
    }
});

// ================================
// LOGOUT
// ================================

$("#changeUserBtn").addEventListener("click", async () => {
    const logoutBtn = $("#changeUserBtn");
    logoutBtn.disabled = true;

    try {
        await authRequest("logout.php", {
            method: "POST"
        });

        shouldReconnect = false;

        clearTimeout(reconnectTimer);
        reconnectTimer = null;

        reconnectAttempts = 0;

        updateConnectionStatus("disconnected");

        if (websocket) {
            websocket.onclose = null;
            websocket.close();
            websocket = null;
        }

        state.userId = null;
        state.nickname = "";
        state.currentRoom = null;
        state.rooms = [];
        state.members = [];
        state.messages = [];

        currentRoomPassword = "";
        roomCounts = {};

        localStorage.removeItem("realtime_chat_nickname");

        lobbyScreen.classList.add("hidden");
        chatScreen.classList.add("hidden");
        welcomeScreen.classList.remove("hidden");

        $("#nickname").value = "";
        authPassword.value = "";

        authMode = "login";
        updateAuthMode();

        $("#nickname").focus();

        showToast("Đăng xuất thành công!");

    } catch (error) {
        console.error("Logout error:", error);
        showToast(error.message);

    } finally {
        logoutBtn.disabled = false;
    }
});

// ================================
// JOIN ROOM
// ================================


roomGrid.addEventListener("click", event => {
    const button = event.target.closest("[data-room-id]");

    if (!button) return;

    const room = state.rooms.find(
        room => room.id === Number(button.dataset.roomId)
    );

    if (!room) return;

    let password = "";

    if (room.private) {
        password = prompt(
            `Nhập mật khẩu để tham gia phòng "${room.name}":`
        );

        if (password === null) return;

        if (!password) {
            showToast("Vui lòng nhập mật khẩu.");
            return;
        }
    }

    openChat(room, password);
});


// ================================
// BACK TO LOBBY
// ================================

$("#backLobbyBtn").addEventListener("click", () => {
    if (websocket?.readyState === WebSocket.OPEN) {
        sendWebSocket({
            type: "leave_room"
        });
    }

    state.currentRoom = null;
    currentRoomPassword = "";
    state.members = [];
    state.messages = [];

    openLobby();
});

// ================================
// ROOM MODAL
// ================================


$("#createRoomForm").addEventListener("submit", async event => {
    event.preventDefault();

    const form = event.currentTarget;
    const submitBtn = form.querySelector('button[type="submit"]');

    const name = $("#roomName").value.trim();
    const isPrivate = $("#privateRoom").checked;
    const password = $("#roomPassword").value;

    if (!name || name.length > 40) {
        return showToast("Tên phòng phải có từ 1 đến 40 ký tự.");
    }

    if (isPrivate && !password) {
        return showToast("Phòng riêng cần có mật khẩu.");
    }

    submitBtn.disabled = true;

    try {
        const response = await fetch("backend/api/create_room.php", {
            method: "POST",
            credentials: "same-origin",

            headers: {
                "Content-Type": "application/json"
            },
            body: JSON.stringify({
                name: name,
                private: isPrivate,
                password: isPrivate ? password : ""
            })
        });

        const data = await response.json();

        if (!response.ok || !data.success) {
            throw new Error(data.message || "Không thể tạo phòng.");
        }

        roomModal.classList.add("hidden");
        form.reset();
        $("#passwordField").classList.add("hidden");


        
        // Cập nhật danh sách phòng trên trình duyệt hiện tại
        await loadRooms();

        // Thông báo phòng mới cho các trình duyệt khác
        sendWebSocket({
            type: "room_created",
            room_id: data.room.id
        });

showToast("Đã tạo phòng thành công!");


    } catch (error) {
        console.error("Create room error:", error);
        showToast(error.message);
    } finally {
        submitBtn.disabled = false;
    }
});


$("#closeModalBtn").addEventListener("click", () => {
    roomModal.classList.add("hidden");
});

$("#privateRoom").addEventListener("change", event => {
    $("#passwordField").classList.toggle(
        "hidden",
        !event.target.checked
    );

    if (!event.target.checked) {
        $("#roomPassword").value = "";
    }
});

// ================================
// CREATE ROOM (FRONTEND DEMO)
// ================================


$("#createRoomBtn").addEventListener("click", () => {
    console.log("Đã nhấn nút Tạo phòng");

    const modal = document.querySelector("#roomModal");

    if (!modal) {
        console.error("Không tìm thấy #roomModal");
        return;
    }

    modal.classList.remove("hidden");
    document.querySelector("#roomName").focus();
});


// ================================
// SEND MESSAGE
// ================================

$("#messageForm").addEventListener("submit", event => {
    event.preventDefault();

    const input = $("#messageInput");
    const text = input.value.trim();

    if (!text || !state.currentRoom) {
        return;
    }

    if (text.length > 2000) {
        showToast(
            "Tin nhắn không được quá 2000 ký tự."
        );
        return;
    }

    const sent = sendWebSocket({
        type: "send_message",
        room_id: state.currentRoom.id,
        content: text
    });

    if (sent) {
        input.value = "";

        clearTimeout(typingTimer);

        sendWebSocket({
            type: "typing",
            is_typing: false
        });
    }
});

// ================================
// TYPING INDICATOR (LOCAL DEMO)
// ================================

let typingTimer;

$("#messageInput").addEventListener("input", () => {
    if (!state.currentRoom) return;

    sendWebSocket({
        type: "typing",
        is_typing: true
    });

    clearTimeout(typingTimer);

    typingTimer = setTimeout(() => {
        sendWebSocket({
            type: "typing",
            is_typing: false
        });
    }, 900);
});

// ================================
// RESTORE LOGIN SESSION
// ================================

async function restoreSession() {
    try {
        const data = await authRequest("me.php");

        setAuthenticatedUser(data.user);

        openLobby();

        console.log(
            "Session restored:",
            data.user.nickname
        );

    } catch (error) {
        state.userId = null;
        state.nickname = "";

        lobbyScreen.classList.add("hidden");
        chatScreen.classList.add("hidden");
        welcomeScreen.classList.remove("hidden");

        $("#nickname").focus();
    }
}

updateAuthMode();
restoreSession();
