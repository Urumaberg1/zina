/* ======================================
   SocialVibe - Social Media App
   JavaScript Application Logic
   ====================================== */

// ===== DATA MANAGEMENT =====

// Default sample posts
const DEFAULT_POSTS = [
    {
        id: 1,
        author: "Sara Ahmed",
        authorAvatar: "S",
        avatarStyle: "background: linear-gradient(135deg, #667eea, #764ba2)",
        time: "2 hours ago",
        content: "Just finished building my first web application! 🚀 The feeling of seeing your code come to life is absolutely incredible. #WebDevelopment #JavaScript #Coding",
        image: null,
        likes: 42,
        liked: false,
        comments: [
            { author: "Mohamed Ali", avatar: "M", avatarStyle: "background: linear-gradient(135deg, #667eea, #764ba2)", text: "Congratulations! 🎉 That's amazing!", time: "1 hour ago", liked: false },
            { author: "Layla Hassan", avatar: "L", avatarStyle: "background: linear-gradient(135deg, #f093fb, #f5576c)", text: "So proud of you! Keep up the great work 💪", time: "45 min ago", liked: false }
        ],
        shares: 5,
        showComments: false,
        timestamp: Date.now() - 7200000
    },
    {
        id: 2,
        author: "Mohamed Ali",
        authorAvatar: "M",
        avatarStyle: "background: linear-gradient(135deg, #4facfe, #00f2fe)",
        time: "5 hours ago",
        content: "Beautiful sunset at the beach today 🌅 Sometimes you need to take a break from coding and enjoy nature.",
        image: null,
        likes: 89,
        liked: false,
        comments: [
            { author: "Sara Ahmed", avatar: "S", avatarStyle: "background: linear-gradient(135deg, #667eea, #764ba2)", text: "Absolutely stunning! Where is this? 😍", time: "4 hours ago", liked: false }
        ],
        shares: 12,
        showComments: false,
        timestamp: Date.now() - 18000000
    },
    {
        id: 3,
        author: "Layla Hassan",
        authorAvatar: "L",
        avatarStyle: "background: linear-gradient(135deg, #f093fb, #f5576c)",
        time: "Yesterday",
        content: "📚 Just published my new article about CSS Grid vs Flexbox. Understanding when to use each one can really level up your front-end skills!\n\nKey takeaways:\n✅ Use Flexbox for one-dimensional layouts\n✅ Use Grid for two-dimensional layouts\n✅ They work great together!\n\nLink in bio 👆",
        image: null,
        likes: 156,
        liked: false,
        comments: [
            { author: "Ahmed Nasser", avatar: "A", avatarStyle: "background: linear-gradient(135deg, #43e97b, #38f9d7)", text: "Great article! Very well explained 👏", time: "23 hours ago", liked: false },
            { author: "Nour Elsayed", avatar: "N", avatarStyle: "background: linear-gradient(135deg, #fa709a, #fee140)", text: "This is exactly what I needed. Thank you!", time: "22 hours ago", liked: false },
            { author: "Omar Khaled", avatar: "O", avatarStyle: "background: linear-gradient(135deg, #4facfe, #00f2fe)", text: "Bookmarked for later! 📌", time: "20 hours ago", liked: false }
        ],
        shares: 34,
        showComments: false,
        timestamp: Date.now() - 86400000
    },
    {
        id: 4,
        author: "Ahmed Nasser",
        authorAvatar: "A",
        avatarStyle: "background: linear-gradient(135deg, #43e97b, #38f9d7)",
        time: "2 days ago",
        content: "🎓 Excited to announce that I've been accepted into the Advanced Web Development program! Hard work really does pay off. Thank you to everyone who supported me on this journey! ❤️",
        image: null,
        likes: 234,
        liked: false,
        comments: [
            { author: "Sara Ahmed", avatar: "S", avatarStyle: "background: linear-gradient(135deg, #667eea, #764ba2)", text: "You deserve it! Congratulations! 🎉🎉", time: "2 days ago", liked: false },
            { author: "Mohamed Ali", avatar: "M", avatarStyle: "background: linear-gradient(135deg, #4facfe, #00f2fe)", text: "Well done brother! 🙌", time: "2 days ago", liked: false }
        ],
        shares: 18,
        showComments: false,
        timestamp: Date.now() - 172800000
    }
];

// Get posts from localStorage or use defaults
function getPosts() {
    const stored = localStorage.getItem('socialvibe_posts');
    if (stored) {
        return JSON.parse(stored);
    }
    localStorage.setItem('socialvibe_posts', JSON.stringify(DEFAULT_POSTS));
    return DEFAULT_POSTS;
}

// Save posts to localStorage
function savePosts(posts) {
    localStorage.setItem('socialvibe_posts', JSON.stringify(posts));
}

// Get user profile
function getProfile() {
    const stored = localStorage.getItem('socialvibe_profile');
    if (stored) {
        return JSON.parse(stored);
    }
    const defaultProfile = {
        name: "User Name",
        bio: "Welcome to SocialVibe! 🚀",
        work: "Tech Company",
        education: "University",
        location: "Cairo, Egypt"
    };
    localStorage.setItem('socialvibe_profile', JSON.stringify(defaultProfile));
    return defaultProfile;
}

// ===== PAGE INITIALIZATION =====

document.addEventListener('DOMContentLoaded', function() {
    updateNavProfile();
    
    // Load feed if on home page
    const feedContainer = document.getElementById('postsFeed');
    if (feedContainer) {
        loadFeed();
        updateSidebar();
    }
});

function updateNavProfile() {
    const profile = getProfile();
    const initial = profile.name.charAt(0).toUpperCase();
    
    // Update all avatar instances
    document.querySelectorAll('#navAvatar, #createPostAvatar, #modalAvatar, #sidebarAvatar').forEach(el => {
        if (el) el.textContent = initial;
    });
    
    // Update name displays
    const modalName = document.getElementById('modalUserName');
    if (modalName) modalName.textContent = profile.name;
    
    const sidebarName = document.getElementById('sidebarName');
    if (sidebarName) sidebarName.textContent = profile.name;
    
    const sidebarBio = document.getElementById('sidebarBio');
    if (sidebarBio) sidebarBio.textContent = profile.bio;
}

function updateSidebar() {
    const posts = getPosts();
    const profile = getProfile();
    const userPosts = posts.filter(p => p.author === profile.name);
    
    const postCount = document.getElementById('sidebarPostCount');
    if (postCount) postCount.textContent = userPosts.length;
}

// ===== FEED MANAGEMENT =====

function loadFeed() {
    const posts = getPosts();
    const container = document.getElementById('postsFeed');
    
    if (posts.length === 0) {
        container.innerHTML = `
            <div class="empty-state">
                <i class="fas fa-newspaper"></i>
                <h3>No posts yet</h3>
                <p>Be the first to share something!</p>
            </div>
        `;
        return;
    }
    
    container.innerHTML = posts.map(post => createPostHTML(post)).join('');
}

// ===== POST CREATION =====

function createPostHTML(post) {
    const commentsHTML = post.comments.map((comment, cIndex) => `
        <div class="comment">
            <div class="avatar avatar-sm" style="${comment.avatarStyle || ''}">${comment.avatar}</div>
            <div class="comment-body">
                <div class="comment-bubble">
                    <strong>${escapeHTML(comment.author)}</strong>
                    <p>${escapeHTML(comment.text)}</p>
                </div>
                <div class="comment-actions">
                    <span class="${comment.liked ? 'liked' : ''}" onclick="toggleCommentLike(${post.id}, ${cIndex})">Like</span>
                    <span>Reply</span>
                    <span>${comment.time}</span>
                </div>
            </div>
        </div>
    `).join('');
    
    const imageHTML = post.image ? `<img src="${post.image}" class="post-image" alt="Post image" onclick="openImageFullscreen(this)">` : '';
    
    const likesDisplay = post.likes > 0 ? `
        <div class="likes-count" onclick="showLikers(${post.id})">
            <div class="like-icons">
                <span class="like-icon-badge thumbs"><i class="fas fa-thumbs-up"></i></span>
                <span class="like-icon-badge heart"><i class="fas fa-heart"></i></span>
            </div>
            ${post.likes}
        </div>
    ` : '<div></div>';
    
    return `
        <div class="post-card" id="post-${post.id}">
            <div class="post-header">
                <div class="avatar avatar-md" style="${post.avatarStyle || ''}">${post.authorAvatar}</div>
                <div class="post-user-info">
                    <strong>${escapeHTML(post.author)}</strong>
                    <div class="post-time">
                        <i class="fas fa-globe" style="font-size: 11px;"></i> ${post.time}
                    </div>
                </div>
                <div class="post-menu-btn" onclick="togglePostMenu(event, ${post.id})">
                    <i class="fas fa-ellipsis"></i>
                    <div class="post-menu" id="menu-${post.id}">
                        <button class="post-menu-item" onclick="savePost(${post.id})">
                            <i class="fas fa-bookmark"></i> Save Post
                        </button>
                        <button class="post-menu-item" onclick="hidePost(${post.id})">
                            <i class="fas fa-eye-slash"></i> Hide Post
                        </button>
                        <button class="post-menu-item delete" onclick="deletePost(${post.id})">
                            <i class="fas fa-trash"></i> Delete Post
                        </button>
                    </div>
                </div>
            </div>
            <div class="post-content">${escapeHTML(post.content)}</div>
            ${imageHTML}
            <div class="post-stats">
                ${likesDisplay}
                <div class="comments-shares-count">
                    <span onclick="toggleComments(${post.id})">${post.comments.length} comment${post.comments.length !== 1 ? 's' : ''}</span> · 
                    <span>${post.shares} shares</span>
                </div>
            </div>
            <div class="post-actions">
                <button class="action-btn ${post.liked ? 'liked' : ''}" onclick="toggleLike(${post.id})">
                    <i class="fa${post.liked ? 's' : 'r'} fa-heart"></i>
                    <span>Like</span>
                </button>
                <button class="action-btn" onclick="toggleComments(${post.id})">
                    <i class="far fa-comment"></i>
                    <span>Comment</span>
                </button>
                <button class="action-btn" onclick="sharePost(${post.id})">
                    <i class="fas fa-share"></i>
                    <span>Share</span>
                </button>
            </div>
            <div class="comments-section" id="comments-${post.id}" style="display: ${post.showComments ? 'block' : 'none'};">
                ${commentsHTML}
                <div class="comment-input-wrapper">
                    <div class="avatar avatar-sm" id="commentAvatar-${post.id}">${getProfile().name.charAt(0).toUpperCase()}</div>
                    <input type="text" class="comment-input" id="commentInput-${post.id}" 
                        placeholder="Write a comment..." 
                        onkeypress="handleCommentKey(event, ${post.id})">
                    <button class="comment-send-btn" onclick="addComment(${post.id})">
                        <i class="fas fa-paper-plane"></i>
                    </button>
                </div>
            </div>
        </div>
    `;
}

// ===== POST INTERACTIONS =====

function toggleLike(postId) {
    const posts = getPosts();
    const post = posts.find(p => p.id === postId);
    if (!post) return;
    
    post.liked = !post.liked;
    post.likes += post.liked ? 1 : -1;
    savePosts(posts);
    
    // Refresh the post
    refreshPost(postId);
    
    if (post.liked) {
        showToast('You liked this post ❤️');
    }
}

function toggleComments(postId) {
    const posts = getPosts();
    const post = posts.find(p => p.id === postId);
    if (!post) return;
    
    post.showComments = !post.showComments;
    savePosts(posts);
    
    const commentsSection = document.getElementById(`comments-${postId}`);
    if (commentsSection) {
        commentsSection.style.display = post.showComments ? 'block' : 'none';
        if (post.showComments) {
            const input = document.getElementById(`commentInput-${postId}`);
            if (input) input.focus();
        }
    }
}

function addComment(postId) {
    const input = document.getElementById(`commentInput-${postId}`);
    const text = input.value.trim();
    if (!text) return;
    
    const posts = getPosts();
    const post = posts.find(p => p.id === postId);
    if (!post) return;
    
    const profile = getProfile();
    const newComment = {
        author: profile.name,
        avatar: profile.name.charAt(0).toUpperCase(),
        avatarStyle: "background: var(--gradient-primary)",
        text: text,
        time: "Just now",
        liked: false
    };
    
    post.comments.push(newComment);
    post.showComments = true;
    savePosts(posts);
    
    // Refresh the post
    refreshPost(postId);
    showToast('Comment added! 💬');
}

function handleCommentKey(event, postId) {
    if (event.key === 'Enter') {
        addComment(postId);
    }
}

function toggleCommentLike(postId, commentIndex) {
    const posts = getPosts();
    const post = posts.find(p => p.id === postId);
    if (!post || !post.comments[commentIndex]) return;
    
    post.comments[commentIndex].liked = !post.comments[commentIndex].liked;
    savePosts(posts);
    refreshPost(postId);
}

function sharePost(postId) {
    const posts = getPosts();
    const post = posts.find(p => p.id === postId);
    if (!post) return;
    
    post.shares += 1;
    savePosts(posts);
    refreshPost(postId);
    showToast('Post shared! 🔗');
}

function deletePost(postId) {
    if (!confirm('Are you sure you want to delete this post?')) return;
    
    let posts = getPosts();
    posts = posts.filter(p => p.id !== postId);
    savePosts(posts);
    
    const postElement = document.getElementById(`post-${postId}`);
    if (postElement) {
        postElement.style.animation = 'fadeOut 0.3s ease';
        setTimeout(() => {
            // Refresh appropriate section
            const feedContainer = document.getElementById('postsFeed');
            if (feedContainer) loadFeed();
            
            const profileContainer = document.getElementById('profilePosts');
            if (profileContainer && typeof loadProfilePosts === 'function') loadProfilePosts();
        }, 300);
    }
    
    showToast('Post deleted 🗑️');
}

function savePost(postId) {
    showToast('Post saved! 🔖');
}

function hidePost(postId) {
    const postElement = document.getElementById(`post-${postId}`);
    if (postElement) {
        postElement.style.animation = 'fadeOut 0.3s ease forwards';
        setTimeout(() => postElement.remove(), 300);
    }
    showToast('Post hidden from your feed');
}

function refreshPost(postId) {
    const posts = getPosts();
    const post = posts.find(p => p.id === postId);
    if (!post) return;
    
    const postElement = document.getElementById(`post-${postId}`);
    if (postElement) {
        const temp = document.createElement('div');
        temp.innerHTML = createPostHTML(post);
        postElement.replaceWith(temp.firstElementChild);
    }
}

// ===== POST MENU =====

function togglePostMenu(event, postId) {
    event.stopPropagation();
    const menu = document.getElementById(`menu-${postId}`);
    
    // Close all other menus
    document.querySelectorAll('.post-menu').forEach(m => {
        if (m.id !== `menu-${postId}`) m.classList.remove('active');
    });
    
    menu.classList.toggle('active');
}

// Close menus when clicking outside
document.addEventListener('click', function() {
    document.querySelectorAll('.post-menu').forEach(m => m.classList.remove('active'));
});

// ===== CREATE POST MODAL =====

let postImageData = null;

function openPostModal() {
    document.getElementById('postModal').classList.add('active');
    document.getElementById('postContent').focus();
    document.body.style.overflow = 'hidden';
}

function closePostModal() {
    document.getElementById('postModal').classList.remove('active');
    document.getElementById('postContent').value = '';
    removeImage();
    document.body.style.overflow = '';
}

function previewPostImage(event) {
    const file = event.target.files[0];
    if (file) {
        const reader = new FileReader();
        reader.onload = function(e) {
            postImageData = e.target.result;
            document.getElementById('previewImg').src = postImageData;
            document.getElementById('imagePreview').style.display = 'block';
        }
        reader.readAsDataURL(file);
    }
}

function removeImage() {
    postImageData = null;
    document.getElementById('imagePreview').style.display = 'none';
    document.getElementById('previewImg').src = '';
    const fileInput = document.getElementById('postImage');
    if (fileInput) fileInput.value = '';
}

function createPost() {
    const content = document.getElementById('postContent').value.trim();
    if (!content && !postImageData) {
        showToast('Please write something or add an image! ✍️');
        return;
    }
    
    const profile = getProfile();
    const posts = getPosts();
    
    const newPost = {
        id: Date.now(),
        author: profile.name,
        authorAvatar: profile.name.charAt(0).toUpperCase(),
        avatarStyle: "",
        time: "Just now",
        content: content,
        image: postImageData,
        likes: 0,
        liked: false,
        comments: [],
        shares: 0,
        showComments: false,
        timestamp: Date.now()
    };
    
    posts.unshift(newPost);
    savePosts(posts);
    
    closePostModal();
    
    // Refresh the feed or profile posts
    const feedContainer = document.getElementById('postsFeed');
    if (feedContainer) loadFeed();
    
    const profileContainer = document.getElementById('profilePosts');
    if (profileContainer && typeof loadProfilePosts === 'function') loadProfilePosts();
    
    updateSidebar();
    showToast('Post created successfully! 🎉');
}

function addEmoji() {
    const emojis = ['😀', '😍', '🔥', '👏', '🎉', '💯', '❤️', '😎', '🚀', '✨', '💪', '🙌', '😂', '🤔', '👍', '⭐'];
    const textarea = document.getElementById('postContent');
    const randomEmoji = emojis[Math.floor(Math.random() * emojis.length)];
    textarea.value += randomEmoji;
    textarea.focus();
}

// ===== SEARCH =====

function searchPosts() {
    const query = document.getElementById('searchInput').value.toLowerCase();
    const posts = getPosts();
    
    if (!query) {
        loadFeed();
        return;
    }
    
    const filtered = posts.filter(p => 
        p.content.toLowerCase().includes(query) || 
        p.author.toLowerCase().includes(query)
    );
    
    const container = document.getElementById('postsFeed');
    if (filtered.length === 0) {
        container.innerHTML = `
            <div class="empty-state">
                <i class="fas fa-search"></i>
                <h3>No results found</h3>
                <p>Try a different search term</p>
            </div>
        `;
        return;
    }
    
    container.innerHTML = filtered.map(post => createPostHTML(post)).join('');
}

// ===== IMAGE FULLSCREEN =====

function openImageFullscreen(img) {
    const overlay = document.createElement('div');
    overlay.style.cssText = `
        position: fixed; top: 0; left: 0; right: 0; bottom: 0;
        background: rgba(0,0,0,0.9); z-index: 5000;
        display: flex; align-items: center; justify-content: center;
        cursor: pointer; animation: fadeIn 0.3s ease;
    `;
    
    const fullImg = document.createElement('img');
    fullImg.src = img.src;
    fullImg.style.cssText = 'max-width: 90%; max-height: 90%; object-fit: contain; border-radius: 8px;';
    
    overlay.appendChild(fullImg);
    overlay.onclick = () => overlay.remove();
    document.body.appendChild(overlay);
}

// ===== TOAST NOTIFICATION =====

function showToast(message) {
    const toast = document.getElementById('toast');
    const toastMessage = document.getElementById('toastMessage');
    toastMessage.textContent = message;
    toast.classList.add('show');
    
    setTimeout(() => {
        toast.classList.remove('show');
    }, 3000);
}

// ===== UTILITY =====

function escapeHTML(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

// Add fade out animation
const style = document.createElement('style');
style.textContent = `
    @keyframes fadeOut {
        from { opacity: 1; transform: scale(1); }
        to { opacity: 0; transform: scale(0.95); }
    }
`;
document.head.appendChild(style);

// Show likers (placeholder)
function showLikers(postId) {
    showToast('People who liked this post');
}

