/**
 * API Client với hỗ trợ tự động refresh token
 * Sử dụng cho hệ thống Access Token (1 phút) và Refresh Token (1 giờ)
 */

class APIClient {
  constructor(baseURL) {
    this.baseURL = baseURL || '/API_Secu/index.php';
    this.isRefreshing = false;
    this.refreshPromise = null;
  }

  /**
   * Thực hiện API request với tự động xử lý token
   */
  async request(action, data = {}, method = 'POST') {
    const url = `${this.baseURL}?action=${action}`;
    
    const options = {
      method: method,
      headers: {
        'Content-Type': 'application/json'
      },
      credentials: 'include', // Quan trọng: Gửi cookies
      body: method !== 'GET' ? JSON.stringify(data) : undefined
    };

    try {
      let response = await fetch(url, options);
      
      // Nếu access token hết hạn (401 Unauthorized)
      if (response.status === 401) {
        const errorData = await response.json();
        
        // Chỉ thử refresh nếu lỗi là token expired
        if (errorData.message?.includes('expired') || errorData.error?.includes('expired')) {
          // Thử refresh token
          const refreshed = await this.refreshAccessToken();
          
          if (refreshed) {
            // Retry request ban đầu với token mới
            response = await fetch(url, options);
          } else {
            // Không thể refresh - chuyển đến trang login
            this.handleAuthFailure();
            throw new Error('Phiên đăng nhập đã hết hạn');
          }
        } else {
          // Lỗi xác thực khác
          this.handleAuthFailure();
          throw new Error(errorData.message || errorData.error || 'Lỗi xác thực');
        }
      }

      // Kiểm tra response
      if (!response.ok) {
        const errorData = await response.json();
        throw new Error(errorData.message || errorData.error || 'Request failed');
      }

      return await response.json();
    } catch (error) {
      console.error('API Request Error:', error);
      throw error;
    }
  }

  /**
   * Refresh access token
   */
  async refreshAccessToken() {
    // Nếu đang refresh, đợi promise hiện tại
    if (this.isRefreshing && this.refreshPromise) {
      return await this.refreshPromise;
    }

    this.isRefreshing = true;
    this.refreshPromise = this._doRefresh();

    try {
      const result = await this.refreshPromise;
      return result;
    } finally {
      this.isRefreshing = false;
      this.refreshPromise = null;
    }
  }

  async _doRefresh() {
    try {
      const response = await fetch(`${this.baseURL}?action=refresh_token`, {
        method: 'POST',
        credentials: 'include'
      });

      if (response.ok) {
        const data = await response.json();
        console.log('Token refreshed successfully');
        return true;
      } else {
        console.error('Failed to refresh token');
        return false;
      }
    } catch (error) {
      console.error('Refresh token error:', error);
      return false;
    }
  }

  /**
   * Xử lý khi xác thực thất bại
   */
  handleAuthFailure() {
    // Xóa thông tin user local nếu có
    localStorage.removeItem('user');
    sessionStorage.removeItem('user');
    
    // Chuyển đến trang login
    if (!window.location.pathname.includes('login')) {
      window.location.href = '/login.html';
    }
  }

  /**
   * Login
   */
  async login(email, fullName, googleID, googleAccessToken, expiresAt) {
    return await this.request('login', {
      email,
      FullName: fullName,
      GoogleID: googleID,
      access_token: googleAccessToken,
      expires_at: expiresAt
    });
  }

  /**
   * Logout
   */
  async logout() {
    try {
      await this.request('logout', {});
      this.handleAuthFailure();
    } catch (error) {
      console.error('Logout error:', error);
      this.handleAuthFailure();
    }
  }

  /**
   * Get data
   */
  async getData(table, scope = 'self', columns = ['*'], conditions = {}) {
    return await this.request('get', {
      table,
      scope,
      columns,
      ...conditions
    });
  }

  /**
   * Add data (Admin only)
   */
  async addData(table, data) {
    return await this.request('add', {
      table,
      ...data
    });
  }

  /**
   * Update data (Admin only)
   */
  async updateData(table, id, data) {
    return await this.request('AdminUpdate', {
      table,
      id,
      ...data
    });
  }

  /**
   * Delete data (Admin only)
   */
  async deleteData(table, id) {
    return await this.request('delete', {
      table,
      id
    });
  }
}

// Export để sử dụng
const apiClient = new APIClient();

// ============================================
// USAGE EXAMPLES
// ============================================

/**
 * Example 1: Login sau khi có Google OAuth
 */
async function exampleLogin() {
  try {
    // Giả sử đã có thông tin từ Google OAuth
    const googleUser = {
      email: 'user@example.com',
      name: 'User Name',
      id: 'google_user_id',
      accessToken: 'google_access_token',
      expiresAt: '2025-12-10 16:00:00'
    };

    const result = await apiClient.login(
      googleUser.email,
      googleUser.name,
      googleUser.id,
      googleUser.accessToken,
      googleUser.expiresAt
    );

    console.log('Login successful:', result);
    // Lưu thông tin user nếu cần
    localStorage.setItem('user', JSON.stringify(result));
    
    // Chuyển đến dashboard
    window.location.href = '/dashboard.html';
  } catch (error) {
    console.error('Login failed:', error);
    alert('Đăng nhập thất bại: ' + error.message);
  }
}

/**
 * Example 2: Lấy thông tin tài khoản
 */
async function exampleGetAccount() {
  try {
    const data = await apiClient.getData('account', 'self');
    console.log('Account data:', data);
    return data;
  } catch (error) {
    console.error('Failed to get account:', error);
  }
}

/**
 * Example 3: Lấy danh sách lớp học (student)
 */
async function exampleGetClasses() {
  try {
    const data = await apiClient.getData('classes', 'all');
    console.log('Classes:', data);
    return data;
  } catch (error) {
    console.error('Failed to get classes:', error);
  }
}

/**
 * Example 4: Admin thêm user mới
 */
async function exampleAddUser() {
  try {
    const result = await apiClient.addData('account', {
      email: 'newuser@example.com',
      FullName: 'New User',
      role: 'student',
      Status: 'Active'
    });
    console.log('User added:', result);
  } catch (error) {
    console.error('Failed to add user:', error);
  }
}

/**
 * Example 5: Logout
 */
async function exampleLogout() {
  try {
    await apiClient.logout();
    console.log('Logged out successfully');
  } catch (error) {
    console.error('Logout failed:', error);
  }
}

/**
 * Example 6: Auto refresh trong interval
 * Để giữ phiên đăng nhập luôn active
 */
function startAutoRefresh() {
  // Refresh mỗi 50 giây (trước khi access token hết hạn 1 phút)
  setInterval(async () => {
    try {
      await apiClient.refreshAccessToken();
      console.log('Token auto-refreshed');
    } catch (error) {
      console.error('Auto refresh failed:', error);
    }
  }, 50000); // 50 seconds
}

/**
 * Example 7: Kiểm tra xem có đang đăng nhập không
 */
async function checkAuth() {
  try {
    // Thử lấy thông tin account
    const data = await apiClient.getData('account', 'self');
    if (data && !data.error) {
      console.log('User is authenticated');
      return true;
    }
  } catch (error) {
    console.log('User is not authenticated');
  }
  return false;
}

/**
 * Example 8: Protected page - Redirect nếu chưa login
 */
async function protectPage() {
  const isAuth = await checkAuth();
  if (!isAuth && !window.location.pathname.includes('login')) {
    window.location.href = '/login.html';
  }
}

// ============================================
// AUTO INITIALIZATION
// ============================================

// Tự động check auth khi load trang (trừ trang login)
document.addEventListener('DOMContentLoaded', async () => {
  const isLoginPage = window.location.pathname.includes('login');
  
  if (!isLoginPage) {
    await protectPage();
    // Optional: Start auto refresh nếu cần
    // startAutoRefresh();
  }
});

// ============================================
// EXPORT
// ============================================
if (typeof module !== 'undefined' && module.exports) {
  module.exports = { APIClient, apiClient };
}
