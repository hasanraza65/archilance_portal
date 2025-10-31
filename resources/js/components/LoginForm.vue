<template>
  <div>
    <h2>Login to External API</h2>
    <form @submit.prevent="submitLogin">
      <input type="text" v-model="email" placeholder="Email" required>
      <input type="password" v-model="password" placeholder="Password" required>
      <button type="submit">Login</button>
    </form>
    <pre v-if="response">{{ response }}</pre>
    <pre v-if="error">{{ error }}</pre>
  </div>
</template>

<script>
export default {
  name: "LoginForm",
  data() {
    return {
      email: '',
      password: '',
      response: null,
      error: null,
    };
  },
  methods: {
    async submitLogin() {
      this.error = this.response = null;
      try {
        const res = await fetch('https://demo.aentora.com/backend/public/api/login', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
          },
          body: JSON.stringify({
            login: this.email,
            password: this.password
          })
        });

        if (!res.ok) throw await res.json();

        const data = await res.json();
        this.response = JSON.stringify(data, null, 2);
      } catch (err) {
        this.error = JSON.stringify(err, null, 2);
      }
    }
  }
}
</script>
