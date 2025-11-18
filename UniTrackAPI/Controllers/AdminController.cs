using Microsoft.AspNetCore.Mvc;
using System.Data.SqlClient;
using System.Security.Cryptography;
using System.Net;
using System.Net.Mail;

namespace UniTrackAPI.Controllers
{
    [ApiController]
    [Route("api/[controller]")]
    public class AdminController : ControllerBase
    {
        private readonly IConfiguration _config;
        
        // Store OTPs temporarily (in production, use Redis or database)
        private static Dictionary<string, OtpData> otpStore = new Dictionary<string, OtpData>();

        public AdminController(IConfiguration config)
        {
            _config = config;
        }

        [HttpPost("login")]
        public IActionResult Login([FromBody] LoginRequest request)
        {
            if (string.IsNullOrEmpty(request.Email) || string.IsNullOrEmpty(request.Password))
                return BadRequest("Email and password are required.");

            using (SqlConnection conn = new SqlConnection(_config.GetConnectionString("DbConn")))
            {
                conn.Open();
                string sql = "SELECT Id, PasswordHash, PasswordSalt, FailedAttempts, IsLocked FROM AdminUsers WHERE Email=@Email";
                using (SqlCommand cmd = new SqlCommand(sql, conn))
                {
                    cmd.Parameters.AddWithValue("@Email", request.Email);
                    using (SqlDataReader reader = cmd.ExecuteReader())
                    {
                        if (!reader.Read())
                            return Unauthorized("Invalid email or password.");

                        int userId = (int)reader["Id"];
                        string hash = (string)reader["PasswordHash"];
                        string salt = (string)reader["PasswordSalt"];
                        int failed = (int)reader["FailedAttempts"];
                        bool isLocked = (bool)reader["IsLocked"];

                        if (isLocked)
                            return Unauthorized("Account is locked.");

                        if (!VerifyPassword(request.Password, hash, salt))
                        {
                            reader.Close();
                            failed++;
                            UpdateFailedAttempts(userId, failed);
                            if (failed >= 3)
                                LockAccount(userId);
                            return Unauthorized($"Invalid password. {3 - failed} attempts remaining.");
                        }

                        reader.Close();
                        UpdateFailedAttempts(userId, 0);

                        return Ok(new { UserId = userId, Email = request.Email });
                    }
                }
            }
        }

        // NEW: Send OTP to email
        [HttpPost("send-otp")]
        public IActionResult SendOtp([FromBody] EmailRequest request)
        {
            if (string.IsNullOrEmpty(request.Email))
                return BadRequest("Email is required.");

            using (SqlConnection conn = new SqlConnection(_config.GetConnectionString("DbConn")))
            {
                conn.Open();
                
                // NEW: Check if email is already registered as a STUDENT
                string checkStudentSql = "SELECT COUNT(*) FROM StudentUsers WHERE Email=@Email";
                using (SqlCommand cmd = new SqlCommand(checkStudentSql, conn))
                {
                    cmd.Parameters.AddWithValue("@Email", request.Email);
                    int studentCount = (int)cmd.ExecuteScalar();
                    if (studentCount > 0)
                        return BadRequest("This email is already registered as a student. Students cannot register as admins.");
                }

                // Check if email already exists as admin
                string checkAdminSql = "SELECT COUNT(*) FROM AdminUsers WHERE Email=@Email";
                using (SqlCommand cmd = new SqlCommand(checkAdminSql, conn))
                {
                    cmd.Parameters.AddWithValue("@Email", request.Email);
                    int adminCount = (int)cmd.ExecuteScalar();
                    if (adminCount > 0)
                        return BadRequest("Email already exists.");
                }
            }

            // Generate 6-digit OTP
            Random rnd = new Random();
            string otp = rnd.Next(100000, 999999).ToString();
            DateTime expiry = DateTime.Now.AddMinutes(10);

            // Store OTP
            otpStore[request.Email] = new OtpData { Otp = otp, Expiry = expiry };

            // Send email
            try
            {
                SendEmailOtp(request.Email, otp);
                return Ok(new { Message = "OTP sent successfully" });
            }
            catch (Exception ex)
            {
                return StatusCode(500, "Failed to send OTP: " + ex.Message);
            }
        }

        // NEW: Verify OTP
        [HttpPost("verify-otp")]
        public IActionResult VerifyOtp([FromBody] OtpVerifyRequest request)
        {
            if (!otpStore.ContainsKey(request.Email))
                return BadRequest("No OTP found for this email.");

            var otpData = otpStore[request.Email];

            if (DateTime.Now > otpData.Expiry)
            {
                otpStore.Remove(request.Email);
                return BadRequest("OTP has expired.");
            }

            if (otpData.Otp != request.Otp)
                return BadRequest("Invalid OTP.");

            otpData.IsVerified = true;
            return Ok(new { Message = "OTP verified successfully" });
        }

        // NEW: Create Account
        [HttpPost("signup")]
        public IActionResult Signup([FromBody] SignupRequest request)
        {
            // Validate OTP was verified
            if (!otpStore.ContainsKey(request.Email) || !otpStore[request.Email].IsVerified)
                return BadRequest("Email not verified. Please verify OTP first.");

            // Validate password
            if (!IsPasswordValid(request.Password))
                return BadRequest("Password does not meet requirements. Must contain uppercase, lowercase, digit, symbol, and be at least 8 characters.");

            using (SqlConnection conn = new SqlConnection(_config.GetConnectionString("DbConn")))
            {
                conn.Open();

                // NEW: Double-check student table before final signup
                string checkStudentSql = "SELECT COUNT(*) FROM StudentUsers WHERE Email=@Email";
                using (SqlCommand cmd = new SqlCommand(checkStudentSql, conn))
                {
                    cmd.Parameters.AddWithValue("@Email", request.Email);
                    int studentCount = (int)cmd.ExecuteScalar();
                    if (studentCount > 0)
                        return BadRequest("This email is already registered as a student. Students cannot register as admins.");
                }

                // Check if username or email exists in admin table
                string checkAdminSql = "SELECT COUNT(*) FROM AdminUsers WHERE Email=@Email OR Username=@Username";
                using (SqlCommand cmd = new SqlCommand(checkAdminSql, conn))
                {
                    cmd.Parameters.AddWithValue("@Email", request.Email);
                    cmd.Parameters.AddWithValue("@Username", request.Username);
                    int count = (int)cmd.ExecuteScalar();
                    if (count > 0)
                        return BadRequest("Email or username already exists.");
                }

                // Hash password
                string hash, salt;
                HashPassword(request.Password, out hash, out salt);

                // Insert new admin
                string insertSql = "INSERT INTO AdminUsers (Username, Email, PasswordHash, PasswordSalt, IsActive, FailedAttempts, IsLocked) VALUES (@Username, @Email, @Hash, @Salt, 1, 0, 0)";
                using (SqlCommand cmd = new SqlCommand(insertSql, conn))
                {
                    cmd.Parameters.AddWithValue("@Username", request.Username);
                    cmd.Parameters.AddWithValue("@Email", request.Email);
                    cmd.Parameters.AddWithValue("@Hash", hash);
                    cmd.Parameters.AddWithValue("@Salt", salt);
                    cmd.ExecuteNonQuery();
                }
            }

            // Clear OTP after successful signup
            otpStore.Remove(request.Email);

            return Ok(new { Message = "Account created successfully" });
        }

        // Helper: Send OTP Email
        private void SendEmailOtp(string email, string otp)
        {
            string senderEmail = "unitracksti@gmail.com";
            string senderPassword = "mtwk vvzw mbde dzrz"; // App password

            MailMessage mail = new MailMessage();
            mail.To.Add(email);
            mail.Subject = "Your OTP Code - UniTrack";
            mail.Body = $"Your OTP code is: {otp}. It expires in 10 minutes.";
            mail.From = new MailAddress(senderEmail);

            using (SmtpClient smtp = new SmtpClient("smtp.gmail.com", 587))
            {
                smtp.UseDefaultCredentials = false;
                smtp.Credentials = new NetworkCredential(senderEmail, senderPassword);
                smtp.EnableSsl = true;
                smtp.Send(mail);
            }
        }

        // Helper: Hash password
        private void HashPassword(string password, out string hash, out string salt)
        {
            using (var derive = new Rfc2898DeriveBytes(password, 16, 10000))
            {
                salt = Convert.ToBase64String(derive.Salt);
                hash = Convert.ToBase64String(derive.GetBytes(20));
            }
        }

        // Helper: Validate password strength
        private bool IsPasswordValid(string password)
        {
            return password.Length >= 8 &&
                   password.Any(char.IsLower) &&
                   password.Any(char.IsUpper) &&
                   password.Any(char.IsDigit) &&
                   password.Any(ch => !char.IsLetterOrDigit(ch));
        }

        // Helper: Verify password
        private bool VerifyPassword(string password, string hash, string salt)
        {
            byte[] saltBytes = Convert.FromBase64String(salt);
            using (var derive = new Rfc2898DeriveBytes(password, saltBytes, 10000))
            {
                string testHash = Convert.ToBase64String(derive.GetBytes(20));
                return testHash == hash;
            }
        }

        private void UpdateFailedAttempts(int userId, int attempts)
        {
            using (SqlConnection con = new SqlConnection(_config.GetConnectionString("DbConn")))
            {
                con.Open();
                string sql = "UPDATE AdminUsers SET FailedAttempts=@Failed WHERE Id=@Id";
                using (SqlCommand cmd = new SqlCommand(sql, con))
                {
                    cmd.Parameters.AddWithValue("@Failed", attempts);
                    cmd.Parameters.AddWithValue("@Id", userId);
                    cmd.ExecuteNonQuery();
                }
            }
        }

        private void LockAccount(int userId)
        {
            using (SqlConnection con = new SqlConnection(_config.GetConnectionString("DbConn")))
            {
                con.Open();
                string sql = "UPDATE AdminUsers SET IsLocked=1 WHERE Id=@Id";
                using (SqlCommand cmd = new SqlCommand(sql, con))
                {
                    cmd.Parameters.AddWithValue("@Id", userId);
                    cmd.ExecuteNonQuery();
                }
            }
        }
    }

    // Request models
    public class LoginRequest
    {
        public string Email { get; set; }
        public string Password { get; set; }
    }

    public class EmailRequest
    {
        public string Email { get; set; }
    }

    public class OtpVerifyRequest
    {
        public string Email { get; set; }
        public string Otp { get; set; }
    }

    public class SignupRequest
    {
        public string Username { get; set; }
        public string Email { get; set; }
        public string Password { get; set; }
    }

    public class OtpData
    {
        public string Otp { get; set; }
        public DateTime Expiry { get; set; }
        public bool IsVerified { get; set; }
    }
}